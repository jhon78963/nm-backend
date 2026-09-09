import { Injectable } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import { EcommerceMailTemplate, MailClientService } from '@app/mail-client';

import { SubmitContactDto } from './dto/submit-contact.dto';
import { SubmitLibroReclamacionesDto } from './dto/submit-libro.dto';
import { SubmitWholesaleQuoteDto } from './dto/submit-wholesale-quote.dto';

@Injectable()
export class InstitutionalService {
  constructor(
    private readonly mailClient: MailClientService,
    private readonly config: ConfigService,
  ) {}

  async submitContact(dto: SubmitContactDto) {
    await this.notifySupport({
      formTitle: 'Contáctanos — tienda web',
      customerName: dto.name.trim(),
      customerEmail: dto.email.trim().toLowerCase(),
      customerPhone: dto.phone?.trim(),
      subject: dto.subject.trim(),
      message: dto.message.trim(),
    });

    return {
      message:
        'Gracias por escribirnos. Te responderemos a la brevedad en el correo indicado.',
    };
  }

  async submitWholesaleQuote(dto: SubmitWholesaleQuoteDto) {
    const quoteNumber = this.buildQuoteNumber();
    const businessTypeLabel = this.mapBusinessTypeLabel(dto.businessType);

    await this.notifySupport({
      formTitle: 'Cotización mayorista — tienda web',
      customerName: dto.contactName.trim(),
      customerEmail: dto.email.trim().toLowerCase(),
      customerPhone: dto.phone.trim(),
      subject: `Cotización mayorista ${quoteNumber}`,
      message: dto.message.trim(),
      metadata: {
        'N° cotización': quoteNumber,
        Negocio: dto.businessName.trim(),
        Ciudad: dto.city.trim(),
        'Tipo de negocio': businessTypeLabel,
        'Líneas de interés': dto.productLines?.trim() || 'No indicado',
        'Cantidad estimada': dto.estimatedUnits?.trim() || 'No indicado',
      },
    });

    return {
      message:
        'Recibimos tu solicitud mayorista. Un asesor te contactará pronto con precios y condiciones.',
      quoteNumber,
    };
  }

  async submitLibroReclamaciones(dto: SubmitLibroReclamacionesDto) {
    const receiptNumber = this.buildReceiptNumber();
    const tipoLabel = dto.tipo === 'reclamo' ? 'Reclamo' : 'Queja';

    await this.notifySupport({
      formTitle: `Libro de reclamaciones — ${tipoLabel}`,
      customerName: dto.nombre.trim(),
      customerEmail: dto.email.trim().toLowerCase(),
      customerPhone: dto.telefono.trim(),
      subject: `${tipoLabel} ${receiptNumber}`,
      message: dto.detalle.trim(),
      metadata: {
        'N° de registro': receiptNumber,
        Tipo: tipoLabel,
        'DNI/CE': dto.documento.trim(),
        Domicilio: dto.domicilio.trim(),
        'Producto/servicio': dto.producto.trim(),
        'Monto reclamado (S/)': dto.monto?.trim() || 'No indicado',
        'Pedido del consumidor': dto.pedido.trim(),
      },
    });

    return {
      message:
        'Tu reclamo o queja fue registrado. Conserva este comprobante y te responderemos en un plazo máximo de 15 días hábiles.',
      receiptNumber,
      tipo: dto.tipo,
    };
  }

  private async notifySupport(input: {
    formTitle: string;
    customerName: string;
    customerEmail: string;
    customerPhone?: string;
    subject?: string;
    message: string;
    metadata?: Record<string, string>;
  }) {
    const supportEmail =
      this.config.get<string>('MAIL_SUPPORT_EMAIL') ??
      this.config.get<string>('MAIL_FROM_EMAIL') ??
      'soporte@novedadesmaritex.net.pe';
    const storeUrl =
      this.config.get<string>('ECOMMERCE_STORE_URL') ??
      this.config.get<string>('STORE_URL') ??
      'https://novedadesmaritex.net.pe';

    await this.mailClient.sendEcommerceMail({
      template: EcommerceMailTemplate.INSTITUTIONAL_INQUIRY,
      to: supportEmail,
      data: {
        formTitle: input.formTitle,
        customerName: input.customerName,
        customerEmail: input.customerEmail,
        customerPhone: input.customerPhone,
        subject: input.subject,
        message: input.message,
        metadata: input.metadata,
        storeUrl,
      },
    });
  }

  private buildReceiptNumber(): string {
    const now = new Date();
    const y = now.getFullYear();
    const m = String(now.getMonth() + 1).padStart(2, '0');
    const d = String(now.getDate()).padStart(2, '0');
    const suffix = String(Math.floor(Math.random() * 9000) + 1000);
    return `LR-${y}${m}${d}-${suffix}`;
  }

  private buildQuoteNumber(): string {
    const now = new Date();
    const y = now.getFullYear();
    const m = String(now.getMonth() + 1).padStart(2, '0');
    const d = String(now.getDate()).padStart(2, '0');
    const suffix = String(Math.floor(Math.random() * 9000) + 1000);
    return `B2B-${y}${m}${d}-${suffix}`;
  }

  private mapBusinessTypeLabel(value: SubmitWholesaleQuoteDto['businessType']): string {
    switch (value) {
      case 'tienda':
        return 'Tienda / bazar';
      case 'feria':
        return 'Feria / mercado';
      case 'ecommerce':
        return 'Ecommerce / redes';
      default:
        return 'Otro';
    }
  }
}
