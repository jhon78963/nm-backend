import { ApiProperty, ApiPropertyOptional } from '@nestjs/swagger';
import { IsEmail, IsNotEmpty, IsOptional, IsString } from 'class-validator';

export class TrackOrderDto {
  @ApiProperty({ example: 'NM-20260902-0001' })
  @IsString()
  @IsNotEmpty()
  orderNumber!: string;

  @ApiProperty({ description: 'Correo o teléfono del pedido' })
  @IsString()
  @IsNotEmpty()
  contact!: string;

  @ApiPropertyOptional({ description: 'Token reCAPTCHA v3' })
  @IsOptional()
  @IsString()
  captchaToken?: string;
}

export class PublicOrderQueryDto {
  @ApiProperty({ description: 'Correo del pedido (verificación)' })
  @IsEmail()
  email!: string;
}
