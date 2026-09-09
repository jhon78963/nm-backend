import {
  IsBoolean,
  IsEmail,
  IsIn,
  IsNotEmpty,
  IsOptional,
  IsString,
  MaxLength,
} from 'class-validator';

export class SubmitLibroReclamacionesDto {
  @IsIn(['reclamo', 'queja'])
  tipo!: 'reclamo' | 'queja';

  @IsString()
  @IsNotEmpty()
  @MaxLength(120)
  nombre!: string;

  @IsString()
  @IsNotEmpty()
  @MaxLength(12)
  documento!: string;

  @IsString()
  @IsNotEmpty()
  @MaxLength(200)
  domicilio!: string;

  @IsString()
  @IsNotEmpty()
  @MaxLength(30)
  telefono!: string;

  @IsEmail()
  email!: string;

  @IsString()
  @IsNotEmpty()
  @MaxLength(200)
  producto!: string;

  @IsOptional()
  @IsString()
  @MaxLength(20)
  monto?: string;

  @IsString()
  @IsNotEmpty()
  @MaxLength(4000)
  detalle!: string;

  @IsString()
  @IsNotEmpty()
  @MaxLength(2000)
  pedido!: string;

  @IsBoolean()
  conforme!: boolean;

  @IsOptional()
  @IsString()
  captchaToken?: string;
}
