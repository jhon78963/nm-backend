import { Module } from '@nestjs/common';
import { CheckoutController } from './checkout.controller';
import { CheckoutService } from './checkout.service';
import { SunatModule } from '../sunat/sunat.module';
import { FiscalModule } from '../fiscal/fiscal.module';
import { DatabaseModule } from '@app/database';
import { EventBusModule } from '@app/event-bus';

@Module({
  imports: [DatabaseModule, SunatModule, FiscalModule, EventBusModule.forPublisher()],
  controllers: [CheckoutController],
  providers: [CheckoutService],
})
export class CheckoutModule {}
