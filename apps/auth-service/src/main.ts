import '@app/common/sentry/instrument';
import { NestFactory } from '@nestjs/core';
import {
  FastifyAdapter,
  NestFastifyApplication,
} from '@nestjs/platform-fastify';
import { ValidationPipe, VersioningType } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import { DocumentBuilder, SwaggerModule } from '@nestjs/swagger';
import helmet from '@fastify/helmet';
import multipart from '@fastify/multipart';
import { AppModule } from './app.module';
import { GlobalExceptionFilter } from '@app/common/filters/global-exception.filter';
import { resolveCorsOrigins } from '@app/common/utils/cors-origins.util';

async function bootstrap() {
  const app = await NestFactory.create<NestFastifyApplication>(
    AppModule,
    new FastifyAdapter({ logger: process.env.NODE_ENV !== 'production' }),
  );

  const config = app.get(ConfigService);

  // ── Seguridad ──────────────────────────────────────────────────────────────
  await app.register(helmet, {
    contentSecurityPolicy: false,
  });
  await app.register(multipart, { limits: { fileSize: 5 * 1024 * 1024 } });

  app.enableCors({
    origin: resolveCorsOrigins(config),
    credentials: true,
    methods: ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
  });

  // ── Validación global ──────────────────────────────────────────────────────
  // Equivalente al FormRequest de Laravel con class-validator
  app.useGlobalPipes(
    new ValidationPipe({
      whitelist: true,          // Elimina propiedades no decoradas (equivale a $request->only())
      forbidNonWhitelisted: true,
      transform: true,
      transformOptions: { enableImplicitConversion: true },
    }),
  );

  // ── Filtro global de excepciones ───────────────────────────────────────────
  app.useGlobalFilters(new GlobalExceptionFilter());

  // ── Versionado de API ──────────────────────────────────────────────────────
  app.enableVersioning({ type: VersioningType.URI, defaultVersion: '1' });

  // ── Swagger / OpenAPI ──────────────────────────────────────────────────────
  if (config.get('NODE_ENV') !== 'production') {
    const swaggerConfig = new DocumentBuilder()
      .setTitle('nm-services — Auth Service')
      .setDescription('Autenticación, usuarios, roles y tenant management')
      .setVersion('1.0')
      .addBearerAuth()
      .build();
    const document = SwaggerModule.createDocument(app, swaggerConfig);
    SwaggerModule.setup('api/docs', app, document);
  }

  const port = config.get<number>('APP_PORT', 3001);
  await app.listen(port, '0.0.0.0');
  console.log(`Auth Service running on port ${port}`);
}

bootstrap();
