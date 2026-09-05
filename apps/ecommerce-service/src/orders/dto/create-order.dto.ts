import { ApiProperty, ApiPropertyOptional } from '@nestjs/swagger';
import { Type } from 'class-transformer';
import {
  ArrayMinSize,
  IsArray,
  IsBoolean,
  IsEmail,
  IsInt,
  IsNotEmpty,
  IsNumber,
  IsOptional,
  IsString,
  IsUUID,
  MaxLength,
  Min,
  ValidateNested,
} from 'class-validator';

export class OrderAddressDto {
  @ApiProperty()
  @IsString()
  @IsNotEmpty()
  firstName!: string;

  @ApiProperty()
  @IsString()
  @IsNotEmpty()
  lastName!: string;

  @ApiProperty({ default: 'PE' })
  @IsString()
  @IsNotEmpty()
  country!: string;

  @ApiProperty()
  @IsString()
  @IsNotEmpty()
  address1!: string;

  @ApiPropertyOptional({ default: '' })
  @IsOptional()
  @IsString()
  address2?: string;

  @ApiProperty()
  @IsString()
  @IsNotEmpty()
  city!: string;

  @ApiProperty()
  @IsString()
  @IsNotEmpty()
  state!: string;

  @ApiProperty()
  @IsString()
  @IsNotEmpty()
  postcode!: string;

  @ApiPropertyOptional({ default: '' })
  @IsOptional()
  @IsString()
  phone?: string;
}

export class CreateOrderItemDto {
  @ApiProperty()
  @IsUUID('all')
  productId!: string;

  @ApiProperty()
  @IsUUID('all')
  productSizeId!: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsUUID('all')
  colorId?: string;

  @ApiProperty()
  @IsString()
  @IsNotEmpty()
  name!: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  variation?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  imageUrl?: string;

  @ApiProperty()
  @Type(() => Number)
  @IsInt()
  @Min(1)
  quantity!: number;

  @ApiProperty()
  @Type(() => Number)
  @IsNumber()
  @Min(0)
  unitPrice!: number;
}

export class CreateOrderDto {
  @ApiProperty({ description: 'UUID del almacén del storefront' })
  @IsUUID('all')
  warehouseId!: string;

  @ApiProperty()
  @IsEmail()
  email!: string;

  @ApiProperty({ type: OrderAddressDto })
  @ValidateNested()
  @Type(() => OrderAddressDto)
  billing!: OrderAddressDto;

  @ApiProperty({ type: OrderAddressDto })
  @ValidateNested()
  @Type(() => OrderAddressDto)
  shipping!: OrderAddressDto;

  @ApiPropertyOptional({ default: true })
  @IsOptional()
  @IsBoolean()
  sameAsBilling?: boolean;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  orderNotes?: string;

  @ApiProperty()
  @IsString()
  @IsNotEmpty()
  shippingMethodId!: string;

  @ApiProperty()
  @IsString()
  @IsNotEmpty()
  paymentMethodId!: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  couponCode?: string;

  @ApiPropertyOptional({ description: 'IP del cliente para límites de uso único' })
  @IsOptional()
  @IsString()
  @MaxLength(45)
  clientIp?: string;

  @ApiProperty({ type: [CreateOrderItemDto] })
  @IsArray()
  @ArrayMinSize(1)
  @ValidateNested({ each: true })
  @Type(() => CreateOrderItemDto)
  items!: CreateOrderItemDto[];

  @ApiPropertyOptional({ description: 'Token reCAPTCHA v3' })
  @IsOptional()
  @IsString()
  captchaToken?: string;
}
