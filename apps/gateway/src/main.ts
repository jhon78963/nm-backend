import '@app/common/sentry/instrument';
import { NestFactory } from '@nestjs/core';
import { FastifyAdapter, NestFastifyApplication } from '@nestjs/platform-fastify';
import { ConfigService } from '@nestjs/config';
import { DocumentBuilder, SwaggerModule } from '@nestjs/swagger';
import helmet from '@fastify/helmet';
import type { FastifyInstance, FastifyRequest } from 'fastify';
import { AppModule } from './app.module';
import { GlobalExceptionFilter } from '@app/common/filters/global-exception.filter';
import { LoggingInterceptor } from '@app/common/interceptors/logging.interceptor';
import { resolveCorsOrigins } from '@app/common/utils/cors-origins.util';

async function bootstrap() {
  const app = await NestFactory.create<NestFastifyApplication>(
    AppModule,
    new FastifyAdapter({ logger: process.env.NODE_ENV !== 'production' }),
  );

  // Captura el raw body de multipart/form-data para poder reenviarlo a los microservicios.
  // El gateway NO parsea el contenido — solo lo almacena en rawBody y lo pasa tal cual.
  (app.getHttpAdapter().getInstance() as FastifyInstance).addContentTypeParser(
    'multipart/form-data',
    { parseAs: 'buffer', bodyLimit: 20 * 1024 * 1024 }, // 20 MB gateway limit
    (_req: FastifyRequest, body: Buffer, done) => {
      done(null, body);
    },
  );

  const config = app.get(ConfigService);

  await app.register(helmet, {
    contentSecurityPolicy: false,
    // El frontend (4200) carga imágenes del gateway (3000); same-origin las bloquea.
    crossOriginResourcePolicy: { policy: 'cross-origin' },
  });

  app.enableCors({
    origin: resolveCorsOrigins(config),
    credentials: true,
    methods: ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
  });

  app.useGlobalFilters(new GlobalExceptionFilter());
  app.useGlobalInterceptors(new LoggingInterceptor());

  // Swagger unificado — agrega la documentación de todos los servicios
  if (config.get('NODE_ENV') !== 'production') {
    const swaggerCfg = new DocumentBuilder()
      .setTitle('nm-services — API Gateway')
      .setDescription(
        'Punto de entrada único para el frontend Angular (nm-frontend-v2). ' +
        'Proxifica hacia auth, catalog, inventory, pos, finance, hr y report services.',
      )
      .setVersion('1.0')
      .addBearerAuth()
      .addServer(`http://localhost:${config.get('GATEWAY_PORT', 3000)}`, 'Local')
      .build();

    const document = SwaggerModule.createDocument(app, swaggerCfg);
    SwaggerModule.setup('api/docs', app, document, {
      swaggerOptions: { persistAuthorization: true },
    });
  }

  const port = config.get<number>('GATEWAY_PORT', 3000);
  await app.listen(port, '0.0.0.0');
  console.log(`
  ┌──────────────────────────────────────────────────────┐
  │  Gateway nm-services corriendo en puerto ${port}        │
  │  Swagger: http://localhost:${port}/api/docs             │
  │  Health:  http://localhost:${port}/health               │
  └──────────────────────────────────────────────────────┘`);
}

bootstrap();
