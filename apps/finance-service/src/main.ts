import '@app/common/sentry/instrument';
import * as fs from 'fs';
import * as path from 'path';
import { NestFactory } from '@nestjs/core';
import { FastifyAdapter, NestFastifyApplication } from '@nestjs/platform-fastify';
import { ValidationPipe } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import { DocumentBuilder, OpenAPIObject, SwaggerModule } from '@nestjs/swagger';
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
  await app.register(multipart, { limits: { fileSize: 5 * 1024 * 1024 } });
  app.enableCors({ origin: config.get('FRONTEND_URL', 'http://localhost:4200'), credentials: true });
  app.useGlobalPipes(new ValidationPipe({ whitelist: true, forbidNonWhitelisted: true, transform: true }));
  app.useGlobalFilters(new GlobalExceptionFilter());

  // ── Swagger (solo fuera de producción) ──────────────────────────────────────
  if (config.get('NODE_ENV') !== 'production') {
    const swaggerCfg = new DocumentBuilder()
      .setTitle('Novedades Maritex — Finance Service')
      .setDescription(
        'API de Finanzas: flujo de caja diario/mensual, cuentas acumuladas ' +
        'y resumen financiero consolidado por almacén.',
      )
      .setVersion('1.0')
      .addTag('Cashflow', 'Ingresos y gastos de caja')
      .addTag('Accumulated', 'Cuentas acumuladas y configuración contable')
      .addTag('Financial Summary', 'Resumen financiero consolidado')
      .addBearerAuth(
        { type: 'http', scheme: 'bearer', bearerFormat: 'JWT', in: 'header' },
        'access-token',
      )
      .build();

    const document: OpenAPIObject = SwaggerModule.createDocument(app, swaggerCfg);

    // ── Interfaz web en /api/docs ──────────────────────────────────────────────
    SwaggerModule.setup('api/docs', app, document, {
      swaggerOptions: { persistAuthorization: true },
      customSiteTitle: 'NM — Finance API Docs',
    });

    // ── Exportar JSON físico para Postman (solo desarrollo) ───────────────────
    // process.cwd() siempre es la raíz del monorepo desde donde se lanza el proceso.
    const docsDir = path.join(process.cwd(), 'docs');
    fs.mkdirSync(docsDir, { recursive: true });
    const outputPath = path.join(docsDir, 'finance-service-openapi.json');
    fs.writeFileSync(outputPath, JSON.stringify(document, null, 2), 'utf8');
    console.log(`📄 OpenAPI spec exportada → ${outputPath}`);
  }

  const port = config.get<number>('APP_PORT', 3005);
  await app.listen(port, '0.0.0.0');
  console.log(`✅ Finance Service corriendo en http://localhost:${port}`);
  console.log(`📚 Swagger UI: http://localhost:${port}/api/docs`);
}

bootstrap();
