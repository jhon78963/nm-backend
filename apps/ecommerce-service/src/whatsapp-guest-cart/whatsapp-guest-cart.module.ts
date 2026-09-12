import { Module } from '@nestjs/common';

import { WhatsAppGuestCartController } from './whatsapp-guest-cart.controller';
import { WhatsAppGuestCartRepository } from './whatsapp-guest-cart.repository';
import { WhatsAppGuestCartService } from './whatsapp-guest-cart.service';

@Module({
  controllers: [WhatsAppGuestCartController],
  providers: [WhatsAppGuestCartRepository, WhatsAppGuestCartService],
  exports: [WhatsAppGuestCartService],
})
export class WhatsAppGuestCartModule {}
