import { Module } from '@nestjs/common';

import { EcommerceInvoicingService } from './ecommerce-invoicing.service';
import { InvoicingClientService } from './invoicing-client.service';

@Module({
  providers: [EcommerceInvoicingService, InvoicingClientService],
  exports: [EcommerceInvoicingService],
})
export class EcommerceInvoicingModule {}
