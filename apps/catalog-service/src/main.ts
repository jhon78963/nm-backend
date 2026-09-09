import '@app/common/sentry/instrument';
import { NestFactory } from '@nestjs/core';
import { FastifyAdapter, NestFastifyApplication } from '@nestjs/platform-fastify';
import { ValidationPipe } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import { DocumentBuilder, SwaggerModule } from '@nestjs/swagger';
import helmet from '@fastify/helmet';
import multipart from '@fastify/multipart';
import { AppModule } from './app.module';
import { GlobalExceptionFilter } from '@app/common/filters/global-exception.filter';

async function bootstrap() {
  const app = await NestFactory.create<NestFastifyApplication>(
    AppModule,
    new FastifyAdapter({ logger: process.env.NODE_ENV !== 'production' }),
  );

  const config = app.get(ConfigService);

  await app.register(helmet, { contentSecurityPolicy: false });
  await app.register(multipart, {
    limits: { fileSize: 10 * 1024 * 1024 }, // 10 MB — iguala UploadSecurityTest
  });

  app.enableCors({
    origin: config.get('FRONTEND_URL', 'http://localhost:4200'),
    credentials: true,
  });

  app.useGlobalPipes(
    new ValidationPipe({ whitelist: true, forbidNonWhitelisted: true, transform: true }),
  );
  app.useGlobalFilters(new GlobalExceptionFilter());

  if (config.get('NODE_ENV') !== 'production') {
    const swaggerCfg = new DocumentBuilder()
      .setTitle('nm-services — Catalog Service')
      .setDescription('Gestión de productos, colores, tallas y sincronización WooCommerce')
      .setVersion('1.0')
      .addBearerAuth()
      .build();
    SwaggerModule.setup('api/docs', app, SwaggerModule.createDocument(app, swaggerCfg));
  }

  const port = config.get<number>('APP_PORT', 3002);
  await app.listen(port, '0.0.0.0');
  console.log(`Catalog Service running on port ${port}`);
}

bootstrap();
