import { All, Controller, Module, Req, Res, UseGuards } from '@nestjs/common';
import { ConfigModule } from '@nestjs/config';
import { DatabaseModule } from '@app/database';
import { ProxyService } from './proxy/proxy.service';
import { HealthController } from './health/health.controller';
import { AuditLogModule } from '@app/common/audit/audit.module';
import { AuthModule } from '@app/common/auth/auth.module';
import { GatewayAuthGuard } from './guards/gateway-auth.guard';
import type { FastifyRequest, FastifyReply } from 'fastify';

/**
 * GatewayController — Captura TODAS las rutas /api/* y las proxifica.
 * El JwtAuthGuard aplica globalmente excepto rutas @Public().
 * Las rutas de auth (login, refresh, forgot-password) están marcadas
 * @Public() en el auth-service; el gateway las pasa sin token.
 *
 * Rate limiting vive en cada microservicio (p. ej. auth-service), no aquí:
 * un guard global en el proxy contaba todo el tráfico del admin contra el
 * bucket "login" (5/min) y bloqueaba el login legítimo.
 */
@Controller()
@UseGuards(GatewayAuthGuard)
class GatewayController {
  constructor(private readonly proxy: ProxyService) {}

  @All('api/*')
  async proxyAll(@Req() req: FastifyRequest, @Res() reply: FastifyReply) {
    return this.proxy.forward(req, reply);
  }
}

@Module({
  imports: [
    ConfigModule.forRoot({ isGlobal: true, envFilePath: ['.env.local', '.env'] }),
    DatabaseModule,
    AuditLogModule,
    AuthModule,
  ],
  controllers: [GatewayController, HealthController],
  providers: [ProxyService, GatewayAuthGuard],
})
export class AppModule {}
