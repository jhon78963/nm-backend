import { Module } from '@nestjs/common';

import { MailClientModule } from '@app/mail-client';
import { RecaptchaModule } from '@app/common/recaptcha/recaptcha.module';

import { NewsletterAdminController } from './newsletter-admin.controller';
import { NewsletterMailService } from './newsletter-mail.service';
import { NewsletterController } from './newsletter.controller';
import { NewsletterService } from './newsletter.service';

@Module({
  imports: [RecaptchaModule, MailClientModule],
  controllers: [NewsletterController, NewsletterAdminController],
  providers: [NewsletterService, NewsletterMailService],
  exports: [NewsletterService],
})
export class NewsletterModule {}
