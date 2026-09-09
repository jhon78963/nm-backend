import { EcommerceMailTemplate } from '@app/mail-client';

import {
  renderAddressSection,
  renderInfoRow,
  renderMutedNote,
  renderOrderItemsTable,
  renderOrderTotalsTable,
  renderSectionTitle,
  renderStatusHighlight,
  renderSubHeading,
  renderSuccessHero,
} from './ecommerce-partials';
import {
  formatMoney,
  MailBranding,
  renderButton,
  renderHeading,
  renderLayout,
  renderParagraph,
} from './layout';

type TemplateContext = MailBranding;

export function buildMailContent(
  template: EcommerceMailTemplate,
  data: Record<string, unknown>,
  ctx: TemplateContext,
): { subject: string; html: string; text: string } {
  switch (template) {
    case EcommerceMailTemplate.CUSTOMER_WELCOME:
      return welcomeEmail(data, ctx);
    case EcommerceMailTemplate.CUSTOMER_PASSWORD_RESET:
      return passwordResetEmail(data, ctx);
    case EcommerceMailTemplate.ORDER_CONFIRMATION:
      return orderConfirmationEmail(data, ctx);
    case EcommerceMailTemplate.ORDER_STATUS_UPDATE:
      return orderStatusUpdateEmail(data, ctx);
    case EcommerceMailTemplate.ORDER_DELIVERED:
      return orderDeliveredEmail(data, ctx);
    case EcommerceMailTemplate.ORDER_CANCELLED:
      return orderCancelledEmail(data, ctx);
    case EcommerceMailTemplate.REFUND_STATUS_UPDATE:
      return refundStatusUpdateEmail(data, ctx);
    case EcommerceMailTemplate.REVIEW_APPROVED:
      return reviewApprovedEmail(data, ctx);
    case EcommerceMailTemplate.REVIEW_REJECTED:
      return reviewRejectedEmail(data, ctx);
    case EcommerceMailTemplate.ORDER_PAYMENT_RECEIVED:
      return orderPaymentReceivedEmail(data, ctx);
    case EcommerceMailTemplate.ORDER_STAFF_NEW:
      return orderStaffNewEmail(data, ctx);
    case EcommerceMailTemplate.NEWSLETTER_SUBSCRIBED:
      return newsletterSubscribedEmail(data, ctx);
    case EcommerceMailTemplate.NEWSLETTER_CAMPAIGN:
      return newsletterCampaignEmail(data, ctx);
    case EcommerceMailTemplate.INSTITUTIONAL_INQUIRY:
      return institutionalInquiryEmail(data, ctx);
    default:
      throw new Error(`Plantilla no soportada: ${template}`);
  }
}

function welcomeEmail(data: Record<string, unknown>, ctx: TemplateContext) {
  const customerName = String(data.customerName ?? 'Cliente');
  const storeUrl = String(data.storeUrl ?? ctx.storeUrl);
  const welcomeCouponCode = data.welcomeCouponCode ? String(data.welcomeCouponCode) : '';
  const welcomeCouponDescription = data.welcomeCouponDescription
    ? String(data.welcomeCouponDescription)
    : '';
  const welcomeCouponDiscountType = data.welcomeCouponDiscountType
    ? String(data.welcomeCouponDiscountType)
    : '';
  const welcomeCouponDiscountValue = Number(data.welcomeCouponDiscountValue ?? 0);
  const couponText =
    welcomeCouponCode
      ? welcomeCouponDiscountType === 'percentage'
        ? `${welcomeCouponDiscountValue}% de descuento con el código ${welcomeCouponCode}`
        : `S/ ${welcomeCouponDiscountValue.toFixed(2)} de descuento con el código ${welcomeCouponCode}`
      : '';
  const subject = `¡Bienvenido/a a ${ctx.storeName}!`;
  const body = `
    ${renderHeading(`¡Hola, ${customerName}!`)}
    ${renderParagraph(
      `Gracias por registrarte en ${ctx.storeName}. Tu cuenta ya está lista para que explores nuestro catálogo, guardes tus favoritos y realices pedidos con más rapidez.`,
    )}
    ${
      welcomeCouponCode
        ? `${renderParagraph(
            `${welcomeCouponDescription || 'Te regalamos un cupón de bienvenida'}: ${couponText}.`,
          )}${renderMutedNote(`Usa el código ${welcomeCouponCode} en el checkout.`)}`
        : ''
    }
    ${renderButton(storeUrl, 'Ir a la tienda')}
  `;

  return {
    subject,
    html: renderLayout({ title: subject, preview: subject, body, ...ctx }),
    text: `Hola ${customerName}, gracias por registrarte en ${ctx.storeName}. ${
      couponText ? `Cupón: ${welcomeCouponCode} (${couponText}). ` : ''
    }Visita: ${storeUrl}`,
  };
}

function passwordResetEmail(data: Record<string, unknown>, ctx: TemplateContext) {
  const customerName = data.customerName ? String(data.customerName) : 'Cliente';
  const resetUrl = String(data.resetUrl);
  const expiresInMinutes = Number(data.expiresInMinutes ?? 60);
  const subject = 'Restablece tu contraseña';
  const body = `
    ${renderHeading('Restablecer contraseña')}
    ${renderParagraph(
      `Hola ${customerName}, recibimos una solicitud para restablecer tu contraseña. El enlace expira en ${expiresInMinutes} minutos.`,
    )}
    ${renderButton(resetUrl, 'Restablecer contraseña')}
    ${renderMutedNote('Si no solicitaste este cambio, ignora este correo.')}
  `;

  return {
    subject,
    html: renderLayout({ title: subject, preview: subject, body, ...ctx }),
    text: `Restablece tu contraseña: ${resetUrl}`,
  };
}

function orderConfirmationEmail(data: Record<string, unknown>, ctx: TemplateContext) {
  const customerName = String(data.customerName ?? 'Cliente');
  const orderNumber = String(data.orderNumber);
  const items = (data.items as Array<Record<string, unknown>>) ?? [];
  const subtotal = Number(data.subtotal ?? 0);
  const shippingTotal = Number(data.shippingTotal ?? 0);
  const couponDiscount = Number(data.couponDiscount ?? 0);
  const total = Number(data.total ?? 0);
  const shippingMethodTitle = String(data.shippingMethodTitle ?? '');
  const paymentMethodTitle = String(data.paymentMethodTitle ?? '');
  const shippingAddress = (data.shippingAddress as Record<string, unknown>) ?? {};
  const trackUrl = String(data.trackUrl);

  const subject = `Pedido confirmado — ${orderNumber}`;
  const body = `
    ${renderSuccessHero({
      title: '¡Gracias!',
      subtitle: `Hola ${customerName}, recibimos tu pedido y ya estamos preparándolo.`,
      meta: `Pedido: ${orderNumber}`,
    })}
    ${renderSectionTitle('Detalle de tu pedido')}
    ${renderOrderItemsTable(
      items.map((item) => ({
        name: String(item.name),
        variationLabel: item.variationLabel ? String(item.variationLabel) : undefined,
        quantity: Number(item.quantity),
        subtotal: Number(item.subtotal),
        imageUrl: item.imageUrl ? String(item.imageUrl) : undefined,
      })),
    )}
    ${renderOrderTotalsTable({
      subtotal,
      shippingTotal,
      shippingMethodTitle,
      couponDiscount,
      total,
    })}
    ${renderInfoRow('Método de pago', paymentMethodTitle)}
    ${renderAddressSection({ shippingAddress })}
    ${renderButton(trackUrl, 'Seguir mi pedido')}
  `;

  return {
    subject,
    html: renderLayout({
      title: subject,
      preview: `Tu pedido ${orderNumber} fue registrado`,
      body,
      ...ctx,
      footerVariant: 'light',
    }),
    text: `Pedido ${orderNumber} confirmado. Total: ${formatMoney(total)}. Seguimiento: ${trackUrl}`,
  };
}

function orderStatusUpdateEmail(data: Record<string, unknown>, ctx: TemplateContext) {
  const customerName = String(data.customerName ?? 'Cliente');
  const orderNumber = String(data.orderNumber);
  const statusLabel = String(data.statusLabel ?? data.status ?? '');
  const trackingNumber = data.trackingNumber ? String(data.trackingNumber) : undefined;
  const trackingUrl = data.trackingUrl ? String(data.trackingUrl) : undefined;
  const trackUrl = String(data.trackUrl);

  const subject = `Actualización de pedido ${orderNumber}`;
  const body = `
    ${renderHeading('Tu pedido fue actualizado')}
    ${renderParagraph(`Hola ${customerName}, tenemos novedades sobre tu pedido ${orderNumber}.`)}
    ${renderStatusHighlight(statusLabel)}
    ${trackingNumber ? renderInfoRow('Guía de seguimiento', trackingNumber) : ''}
    ${trackingUrl ? renderButton(trackingUrl, 'Rastrear envío') : ''}
    ${renderButton(trackUrl, 'Ver detalle del pedido')}
  `;

  return {
    subject,
    html: renderLayout({ title: subject, preview: `Estado: ${statusLabel}`, body, ...ctx }),
    text: `Pedido ${orderNumber} — ${statusLabel}. ${trackUrl}`,
  };
}

function orderDeliveredEmail(data: Record<string, unknown>, ctx: TemplateContext) {
  const customerName = String(data.customerName ?? 'Cliente');
  const orderNumber = String(data.orderNumber);
  const reviewUrl = String(data.reviewUrl);
  const subject = `¡Tu pedido ${orderNumber} fue entregado!`;

  const body = `
    ${renderHeading(`Hola ${customerName},`)}
    ${renderParagraph(
      `Confirmamos la entrega de tu pedido ${orderNumber}. Esperamos que disfrutes tu compra y nos encantaría conocer tu opinión.`,
    )}
    ${renderSubHeading('¿Qué te pareció tu pedido?')}
    ${renderParagraph('Tu feedback nos ayuda a seguir mejorando y a orientar a otros clientes.', { muted: true })}
    ${renderButton(reviewUrl, 'Dejar una reseña')}
    ${renderSubHeading('¡Gracias!')}
    ${renderParagraph('Tu opinión es muy valiosa para nosotros.', { muted: true })}
  `;

  return {
    subject,
    html: renderLayout({ title: subject, preview: subject, body, ...ctx }),
    text: `Pedido ${orderNumber} entregado. Reseña: ${reviewUrl}`,
  };
}

function orderCancelledEmail(data: Record<string, unknown>, ctx: TemplateContext) {
  const customerName = String(data.customerName ?? 'Cliente');
  const orderNumber = String(data.orderNumber);
  const reason = data.reason ? String(data.reason) : undefined;
  const storeUrl = String(data.storeUrl ?? ctx.storeUrl);
  const subject = `Pedido ${orderNumber} cancelado`;

  const body = `
    ${renderHeading('Pedido cancelado')}
    ${renderParagraph(`Hola ${customerName}, tu pedido ${orderNumber} fue cancelado.`)}
    ${reason ? renderMutedNote(`Motivo: ${reason}`) : ''}
    ${renderButton(storeUrl, 'Volver a la tienda')}
  `;

  return {
    subject,
    html: renderLayout({ title: subject, preview: subject, body, ...ctx }),
    text: `Pedido ${orderNumber} cancelado.`,
  };
}

function refundStatusUpdateEmail(data: Record<string, unknown>, ctx: TemplateContext) {
  const customerName = String(data.customerName ?? 'Cliente');
  const orderNumber = String(data.orderNumber);
  const statusLabel = String(data.statusLabel ?? data.status ?? '');
  const amount = data.amount !== undefined ? Number(data.amount) : undefined;
  const adminNotes = data.adminNotes ? String(data.adminNotes) : undefined;
  const storeUrl = String(data.storeUrl ?? ctx.storeUrl);
  const subject = `Actualización de reembolso — ${orderNumber}`;

  const body = `
    ${renderHeading('Estado de tu reembolso')}
    ${renderParagraph(
      `Hola ${customerName}, el reembolso de tu pedido ${orderNumber} tiene una actualización.`,
    )}
    ${renderStatusHighlight(statusLabel)}
    ${amount !== undefined ? renderInfoRow('Monto', formatMoney(amount)) : ''}
    ${adminNotes ? renderMutedNote(adminNotes) : ''}
    ${renderButton(storeUrl, 'Ir a la tienda')}
  `;

  return {
    subject,
    html: renderLayout({ title: subject, preview: subject, body, ...ctx }),
    text: `Reembolso ${orderNumber}: ${statusLabel}`,
  };
}

function reviewApprovedEmail(data: Record<string, unknown>, ctx: TemplateContext) {
  const customerName = String(data.customerName ?? 'Cliente');
  const productName = String(data.productName);
  const productUrl = String(data.productUrl);
  const subject = 'Tu reseña fue publicada';

  const body = `
    ${renderHeading('¡Gracias por tu opinión!')}
    ${renderParagraph(
      `Hola ${customerName}, tu reseña sobre ${productName} ya está visible en la tienda.`,
    )}
    ${renderButton(productUrl, 'Ver producto')}
  `;

  return {
    subject,
    html: renderLayout({ title: subject, preview: subject, body, ...ctx }),
    text: `Tu reseña de ${productName} fue publicada.`,
  };
}

function reviewRejectedEmail(data: Record<string, unknown>, ctx: TemplateContext) {
  const customerName = String(data.customerName ?? 'Cliente');
  const productName = String(data.productName);
  const reason = data.reason ? String(data.reason) : undefined;
  const storeUrl = String(data.storeUrl ?? ctx.storeUrl);
  const subject = 'Actualización sobre tu reseña';

  const body = `
    ${renderHeading('Reseña no publicada')}
    ${renderParagraph(
      `Hola ${customerName}, no pudimos publicar tu reseña sobre ${productName}.`,
    )}
    ${reason ? renderMutedNote(`Motivo: ${reason}`) : ''}
    ${renderButton(storeUrl, 'Volver a la tienda')}
  `;

  return {
    subject,
    html: renderLayout({ title: subject, preview: subject, body, ...ctx }),
    text: `Reseña de ${productName} no publicada.`,
  };
}

function orderPaymentReceivedEmail(data: Record<string, unknown>, ctx: TemplateContext) {
  const customerName = String(data.customerName ?? 'Cliente');
  const orderNumber = String(data.orderNumber);
  const total = Number(data.total ?? 0);
  const trackUrl = String(data.trackUrl);
  const subject = `Pago confirmado — ${orderNumber}`;

  const body = `
    ${renderSuccessHero({
      title: 'Pago confirmado',
      subtitle: `Hola ${customerName}, recibimos el pago de tu pedido.`,
      meta: `Pedido ${orderNumber} · ${formatMoney(total)}`,
    })}
    ${renderParagraph('Tu pedido continuará su proceso y te avisaremos cuando haya novedades.', { muted: true })}
    ${renderButton(trackUrl, 'Ver mi pedido')}
  `;

  return {
    subject,
    html: renderLayout({ title: subject, preview: subject, body, ...ctx, footerVariant: 'light' }),
    text: `Pago confirmado para pedido ${orderNumber}.`,
  };
}

function orderStaffNewEmail(data: Record<string, unknown>, ctx: TemplateContext) {
  const orderNumber = String(data.orderNumber ?? '');
  const customerName = String(data.customerName ?? 'Cliente');
  const customerEmail = String(data.customerEmail ?? '');
  const total = Number(data.total ?? 0);
  const paymentMethodTitle = String(data.paymentMethodTitle ?? '');
  const shippingMethodTitle = String(data.shippingMethodTitle ?? '');
  const itemCount = Number(data.itemCount ?? 0);
  const orderUrl = String(data.orderUrl ?? '');

  const subject = `[ERP] Nuevo pedido web ${orderNumber}`;
  const body = `
    ${renderHeading('Nuevo pedido en la tienda', { centered: false })}
    ${renderParagraph(
      `Se registró un pedido en ${ctx.storeName}. Revisa y gestiona la orden desde el panel ERP.`,
      { centered: false },
    )}
    ${renderInfoRow('Pedido', orderNumber)}
    ${renderInfoRow('Cliente', customerName)}
    ${renderInfoRow('Correo', customerEmail)}
    ${renderInfoRow('Total', formatMoney(total))}
    ${renderInfoRow('Artículos', String(itemCount))}
    ${renderInfoRow('Pago', paymentMethodTitle)}
    ${renderInfoRow('Envío', shippingMethodTitle)}
    ${renderButton(orderUrl, 'Abrir pedido en ERP')}
  `;

  const textBody = [
    `Nuevo pedido ${orderNumber}`,
    `Cliente: ${customerName}`,
    `Correo: ${customerEmail}`,
    `Total: ${formatMoney(total)}`,
    `Artículos: ${itemCount}`,
    `Pago: ${paymentMethodTitle}`,
    `Envío: ${shippingMethodTitle}`,
    `ERP: ${orderUrl}`,
  ].join('\n');

  return {
    subject,
    html: renderLayout({
      title: subject,
      preview: `Nuevo pedido ${orderNumber} — ${formatMoney(total)}`,
      body,
      ...ctx,
      footerVariant: 'light',
    }),
    text: textBody,
  };
}

function newsletterSubscribedEmail(data: Record<string, unknown>, ctx: TemplateContext) {
  const storeUrl = String(data.storeUrl ?? ctx.storeUrl);
  const subject = `¡Bienvenido al boletín de ${ctx.storeName}!`;
  const body = `
    ${renderHeading('¡Gracias por suscribirte!')}
    ${renderParagraph(
      `Ya formas parte del boletín de ${ctx.storeName}. Te enviaremos novedades, lanzamientos y ofertas exclusivas.`,
    )}
    ${renderButton(storeUrl, 'Visitar la tienda')}
  `;

  return {
    subject,
    html: renderLayout({ title: subject, preview: subject, body, ...ctx }),
    text: `Gracias por suscribirte al boletín de ${ctx.storeName}. Visita: ${storeUrl}`,
  };
}

function newsletterCampaignEmail(data: Record<string, unknown>, ctx: TemplateContext) {
  const title = String(data.title ?? 'Novedades');
  const bodyText = String(data.body ?? '');
  const previewText = data.previewText ? String(data.previewText) : title;
  const ctaUrl = data.ctaUrl ? String(data.ctaUrl) : undefined;
  const ctaLabel = data.ctaLabel ? String(data.ctaLabel) : 'Ver más';
  const storeUrl = String(data.storeUrl ?? ctx.storeUrl);
  const subject = title;

  const paragraphs = bodyText
    .split(/\n{2,}|\n/)
    .map((paragraph) => paragraph.trim())
    .filter(Boolean)
    .map((paragraph) => renderParagraph(paragraph, { centered: false }))
    .join('');

  const body = `
    ${renderHeading(title, { centered: false })}
    ${paragraphs}
    ${ctaUrl ? renderButton(ctaUrl, ctaLabel) : renderButton(storeUrl, 'Ir a la tienda')}
  `;

  return {
    subject,
    html: renderLayout({
      title: subject,
      preview: previewText,
      body,
      ...ctx,
      showSupportBlock: false,
    }),
    text: `${title}\n\n${bodyText}${ctaUrl ? `\n\n${ctaLabel}: ${ctaUrl}` : `\n\n${storeUrl}`}`,
  };
}

function institutionalInquiryEmail(data: Record<string, unknown>, ctx: TemplateContext) {
  const formTitle = String(data.formTitle ?? 'Consulta web');
  const customerName = String(data.customerName ?? 'Cliente');
  const customerEmail = String(data.customerEmail ?? '');
  const customerPhone = data.customerPhone ? String(data.customerPhone) : '';
  const subjectLine = data.subject ? String(data.subject) : formTitle;
  const message = String(data.message ?? '');
  const metadata = (data.metadata ?? {}) as Record<string, string>;
  const metadataLines = Object.entries(metadata)
    .filter(([, value]) => value)
    .map(([key, value]) => `${key}: ${value}`)
    .join('\n');

  const subject = `[${ctx.storeName}] ${subjectLine}`;
  const body = `
    ${renderHeading(formTitle, { centered: false })}
    ${renderInfoRow('Nombre', customerName)}
    ${renderInfoRow('Correo', customerEmail)}
    ${customerPhone ? renderInfoRow('Teléfono', customerPhone) : ''}
    ${renderSectionTitle('Mensaje')}
    ${renderParagraph(message.replace(/\n/g, '<br>'), { centered: false })}
    ${metadataLines ? `${renderSectionTitle('Detalle adicional')}${renderParagraph(metadataLines.replace(/\n/g, '<br>'), { centered: false })}` : ''}
  `;

  const textBody = [
    formTitle,
    `Nombre: ${customerName}`,
    `Correo: ${customerEmail}`,
    customerPhone ? `Teléfono: ${customerPhone}` : '',
    '',
    message,
    metadataLines ? `\n${metadataLines}` : '',
  ]
    .filter(Boolean)
    .join('\n');

  return {
    subject,
    html: renderLayout({
      title: subject,
      preview: `${formTitle} — ${customerName}`,
      body,
      ...ctx,
      showSupportBlock: false,
    }),
    text: textBody,
  };
}
