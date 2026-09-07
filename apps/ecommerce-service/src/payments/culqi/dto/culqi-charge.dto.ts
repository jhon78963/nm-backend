import { ApiProperty, ApiPropertyOptional } from '@nestjs/swagger';
import { IsEmail, IsNotEmpty, IsOptional, IsString } from 'class-validator';

export class CulqiChargeDto {
  @ApiProperty({ example: 'NM-20260906-0001' })
  @IsString()
  @IsNotEmpty()
  orderNumber!: string;

  @ApiProperty({ example: 'cliente@ejemplo.com' })
  @IsEmail()
  email!: string;

  @ApiProperty({ description: 'Token Culqi (tkn_test_... / tkn_live_...)' })
  @IsString()
  @IsNotEmpty()
  culqiToken!: string;

  @ApiPropertyOptional({ description: 'Token reCAPTCHA v3' })
  @IsOptional()
  @IsString()
  captchaToken?: string;
}
