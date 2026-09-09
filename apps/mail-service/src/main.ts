import '@app/common/sentry/instrument';
import { NestFactory } from '@nestjs/core';
import { FastifyAdapter, NestFastifyApplication } from '@nestjs/platform-fastify';
import { ValidationPipe, VersioningType } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import { DocumentBuilder, SwaggerModule } from '@nestjs/swagger';
import helmet from '@fastify/helmet';

import { GlobalExceptionFilter } from '@app/common/filters/global-exception.filter';

import { AppModule } from './app.module';

async function bootstrap() {
  const app = await NestFactory.create<NestFastifyApplication>(
    AppModule,
    new FastifyAdapter({ logger: process.env.NODE_ENV !== 'production' }),
  );

  const config = app.get(ConfigService);

  await app.register(helmet, {
    contentSecurityPolicy: false,
  });

  app.enableCors({
    origin: false,
  });

  app.useGlobalPipes(
    new ValidationPipe({ whitelist: true, forbidNonWhitelisted: true, transform: true }),
  );
  app.useGlobalFilters(new GlobalExceptionFilter());

  app.enableVersioning({ type: VersioningType.URI, defaultVersion: '1' });

  if (config.get('NODE_ENV') !== 'production') {
    const swaggerCfg = new DocumentBuilder()
      .setTitle('nm-services — Mail Service')
      .setDescription('Envío de correos transaccionales en cola (Zoho Mail SMTP + BullMQ)')
      .setVersion('1.0')
      .build();
    SwaggerModule.setup('api/docs', app, SwaggerModule.createDocument(app, swaggerCfg));
  }

  const port = config.get<number>('APP_PORT', 3013);
  await app.listen(port, '0.0.0.0');
  console.log(`Mail Service running on port ${port}`);
}

bootstrap();
