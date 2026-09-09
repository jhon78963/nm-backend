import { IsEmail, IsIn, IsNotEmpty, IsOptional, IsString, MaxLength } from 'class-validator';

const BUSINESS_TYPES = ['tienda', 'feria', 'ecommerce', 'otro'] as const;

export class SubmitWholesaleQuoteDto {
  @IsString()
  @IsNotEmpty()
  @MaxLength(120)
  businessName!: string;

  @IsString()
  @IsNotEmpty()
  @MaxLength(120)
  contactName!: string;

  @IsEmail()
  email!: string;

  @IsString()
  @IsNotEmpty()
  @MaxLength(30)
  phone!: string;

  @IsString()
  @IsNotEmpty()
  @MaxLength(80)
  city!: string;

  @IsString()
  @IsIn(BUSINESS_TYPES)
  businessType!: (typeof BUSINESS_TYPES)[number];

  @IsOptional()
  @IsString()
  @MaxLength(500)
  productLines?: string;

  @IsOptional()
  @IsString()
  @MaxLength(40)
  estimatedUnits?: string;

  @IsString()
  @IsNotEmpty()
  @MaxLength(4000)
  message!: string;

  @IsOptional()
  @IsString()
  captchaToken?: string;
}
