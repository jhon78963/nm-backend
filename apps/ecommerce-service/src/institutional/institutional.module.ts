import { Module } from '@nestjs/common';

import { RecaptchaModule } from '@app/common/recaptcha/recaptcha.module';
import { MailClientModule } from '@app/mail-client';

import { InstitutionalController } from './institutional.controller';
import { InstitutionalService } from './institutional.service';

@Module({
  imports: [RecaptchaModule, MailClientModule],
  controllers: [InstitutionalController],
  providers: [InstitutionalService],
})
export class InstitutionalModule {}
