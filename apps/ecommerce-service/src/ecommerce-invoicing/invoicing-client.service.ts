import { Injectable } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';

export interface InvoicingSendResult {
  sunatStatus: string;
  fullInvoiceNumber?: string;
}

@Injectable()
export class InvoicingClientService {
  private readonly invoicingUrl: string;
  private readonly invoicingApiKey: string;

  constructor(private readonly config: ConfigService) {
    this.invoicingUrl = config.get('INVOICING_SERVICE_URL', 'http://localhost:3009').replace(/\/$/, '');
    this.invoicingApiKey = config.get('INVOICING_API_KEY', '');
  }

  async sendInvoice(saleId: string): Promise<InvoicingSendResult> {
    const response = await fetch(`${this.invoicingUrl}/api/invoices/${saleId}/send`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Service-Key': this.invoicingApiKey,
        Accept: 'application/json',
      },
    });

    if (!response.ok) {
      const text = await response.text().catch(() => response.statusText);
      throw new Error(`invoicing-service → ${response.status}: ${text}`);
    }

    const body = (await response.json()) as {
      sunat_status?: string;
      full_invoice_number?: string;
    };

    return {
      sunatStatus: (body.sunat_status ?? 'PENDING').toUpperCase(),
      fullInvoiceNumber: body.full_invoice_number,
    };
  }

  async fetchInvoicePdf(saleId: string): Promise<{ buffer: Buffer; filename: string }> {
    const response = await fetch(`${this.invoicingUrl}/api/invoices/${saleId}/pdf`, {
      method: 'GET',
      headers: {
        'X-Service-Key': this.invoicingApiKey,
        Accept: 'application/pdf',
      },
    });

    if (!response.ok) {
      const text = await response.text().catch(() => response.statusText);
      throw new Error(`invoicing-service → ${response.status}: ${text}`);
    }

    const buffer = Buffer.from(await response.arrayBuffer());
    const disposition = response.headers.get('content-disposition') ?? '';
    const match = disposition.match(/filename="?([^";]+)"?/i);
    const filename = match?.[1] ?? `comprobante-${saleId}.pdf`;

    return { buffer, filename };
  }
}
