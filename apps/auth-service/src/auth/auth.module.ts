import { Module, forwardRef } from '@nestjs/common';
import { JwtModule } from '@nestjs/jwt';
import { PassportModule } from '@nestjs/passport';
import { ConfigModule, ConfigService } from '@nestjs/config';
import { AuthController } from './auth.controller';
import { AuthService } from './auth.service';
import { CustomerAuthService } from './customer-auth.service';
import { JwtStrategy } from './strategies/jwt.strategy';
import { JwtRefreshStrategy } from './strategies/jwt-refresh.strategy';
import { UsersModule } from '../users/users.module';
import { AuditLogModule } from '@app/common/audit/audit.module';
import { RecaptchaModule } from '@app/common/recaptcha/recaptcha.module';
import { DatabaseModule } from '@app/database';
import { MailClientModule } from '@app/mail-client';

@Module({
  imports: [
    PassportModule.register({ defaultStrategy: 'jwt' }),
    JwtModule.registerAsync({
      imports: [ConfigModule],
      inject: [ConfigService],
      useFactory: (config: ConfigService) => ({
        secret: config.getOrThrow<string>('JWT_SECRET'),
        signOptions: {
          expiresIn: config.get<string>('JWT_EXPIRES_IN', '15m'),
        },
      }),
    }),
    forwardRef(() => UsersModule),
    DatabaseModule,
    AuditLogModule,
    MailClientModule,
    RecaptchaModule,
  ],
  controllers: [AuthController],
  providers: [AuthService, CustomerAuthService, JwtStrategy, JwtRefreshStrategy],
  exports: [AuthService, CustomerAuthService, JwtModule, PassportModule, JwtStrategy],
})
export class AuthModule {}
