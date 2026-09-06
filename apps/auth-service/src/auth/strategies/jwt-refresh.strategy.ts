import { Injectable, UnauthorizedException } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import { PassportStrategy } from '@nestjs/passport';
import { ExtractJwt, Strategy } from 'passport-jwt';
import { AuthService } from '../auth.service';

/**
 * Estrategia JWT para refresh tokens (token de larga duración, 7 días por defecto).
 * Los refresh tokens se almacenan en DB y se invalidan al hacer logout.
 * Equivale al `auth/refresh` endpoint con Sanctum dual-token de Laravel.
 *
 * SEGURIDAD: El refresh token NO puede usarse como bearer en rutas normales
 * (equivale al test: "refresh token is not usable as API bearer").
 */
@Injectable()
export class JwtRefreshStrategy extends PassportStrategy(Strategy, 'jwt-refresh') {
  constructor(
    config: ConfigService,
    private readonly authService: AuthService,
  ) {
    super({
      jwtFromRequest: ExtractJwt.fromExtractors([
        ExtractJwt.fromBodyField('refresh_token'),
        ExtractJwt.fromAuthHeaderAsBearerToken(),
      ]),
      ignoreExpiration: false,
      secretOrKey: config.getOrThrow<string>('JWT_REFRESH_SECRET'),
      passReqToCallback: false,
    });
  }

  async validate(payload: { sub: string; jti: string }) {
    const isValid = await this.authService.validateRefreshToken(
      payload.sub,
      payload.jti,
    );
    if (!isValid) {
      throw new UnauthorizedException('Refresh token inválido o expirado.');
    }
    return { id: payload.sub, tokenId: payload.jti };
  }
}
