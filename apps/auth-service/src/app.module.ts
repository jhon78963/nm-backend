import { Module } from '@nestjs/common';
import { ConfigModule } from '@nestjs/config';
import { ThrottlerModule } from '@nestjs/throttler';
import { AuthModule } from './auth/auth.module';
import { UsersModule } from './users/users.module';
import { RolesModule } from './roles/roles.module';
import { WarehousesModule } from './warehouses/warehouses.module';
import { TenantsModule } from './tenants/tenants.module';
import { AuditModule } from './audit/audit.module';
import { ProfileModule } from './profile/profile.module';
import { DatabaseModule } from '@app/database';
import { HealthModule } from '@app/common/health/health.module';

@Module({
  imports: [
    ConfigModule.forRoot({
      isGlobal: true,
      envFilePath: ['.env.local', '.env'],
    }),

    // Rate limiting global — equivalente al `throttle:120,1` middleware de Laravel
    ThrottlerModule.forRoot([
      {
        name: 'global',
        ttl: parseInt(process.env.THROTTLE_TTL ?? '60000'),
        limit: parseInt(process.env.THROTTLE_LIMIT ?? '120'),
      },
      {
        name: 'login',
        ttl: 60_000,
        limit: 5,
      },
    ]),

    DatabaseModule,
    HealthModule,
    AuthModule,
    UsersModule,
    RolesModule,
    WarehousesModule,
    TenantsModule,
    AuditModule,
    ProfileModule,
  ],
})
export class AppModule {}
