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
import { MAX_FILE_SIZE_BYTES } from '@app/storage';

async function bootstrap() {
  const app = await NestFactory.create<NestFastifyApplication>(
    AppModule,
    new FastifyAdapter({ logger: process.env.NODE_ENV !== 'production' }),
  );

  const config = app.get(ConfigService);

  await app.register(helmet, {
    contentSecurityPolicy: false,
    crossOriginResourcePolicy: { policy: 'cross-origin' },
  });

  // Multipart necesario para recibir archivos
  await app.register(multipart, {
    limits: {
      fileSize: MAX_FILE_SIZE_BYTES,
      files: 10,
    },
  });

  app.enableCors({
    origin: config.get('FRONTEND_URL', 'http://localhost:4200'),
    credentials: true,
  });

  app.useGlobalPipes(
    new ValidationPipe({ whitelist: true, forbidNonWhitelisted: true, transform: true }),
  );
  app.useGlobalFilters(new GlobalExceptionFilter());

  app.enableVersioning({ type: (await import('@nestjs/common')).VersioningType.URI, defaultVersion: '1' });

  if (config.get('NODE_ENV') !== 'production') {
    const swaggerCfg = new DocumentBuilder()
      .setTitle('nm-services — Storage Service')
      .setDescription('Almacenamiento de archivos (local / Firebase Storage)')
      .setVersion('1.0')
      .addBearerAuth()
      .build();
    SwaggerModule.setup('api/docs', app, SwaggerModule.createDocument(app, swaggerCfg));
  }

  const port = config.get<number>('APP_PORT', 3008);
  await app.listen(port, '0.0.0.0');
  console.log(`Storage Service running on port ${port}`);
}

bootstrap();
