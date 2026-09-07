import { ApiProperty, ApiPropertyOptional } from '@nestjs/swagger';
import { IsEmail, IsNotEmpty, IsOptional, IsString } from 'class-validator';

export class CulqiPrepareDto {
  @ApiProperty({ example: 'NM-20260906-0001' })
  @IsString()
  @IsNotEmpty()
  orderNumber!: string;

  @ApiProperty({ example: 'cliente@ejemplo.com' })
  @IsEmail()
  email!: string;

  @ApiPropertyOptional({ description: 'Token reCAPTCHA v3' })
  @IsOptional()
  @IsString()
  captchaToken?: string;
}
