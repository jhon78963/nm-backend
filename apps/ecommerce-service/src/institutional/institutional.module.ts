import { Module } from '@nestjs/common';

import { MailClientModule } from '@app/mail-client';

import { InstitutionalController } from './institutional.controller';
import { InstitutionalService } from './institutional.service';

@Module({
  imports: [MailClientModule],
  controllers: [InstitutionalController],
  providers: [InstitutionalService],
})
export class InstitutionalModule {}
