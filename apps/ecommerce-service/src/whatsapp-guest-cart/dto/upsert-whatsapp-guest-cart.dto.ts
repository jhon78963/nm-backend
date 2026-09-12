import { ApiProperty, ApiPropertyOptional } from '@nestjs/swagger';
import { Type } from 'class-transformer';
import {
  ArrayMinSize,
  IsArray,
  IsBoolean,
  IsIn,
  IsInt,
  IsNotEmpty,
  IsNumber,
  IsOptional,
  IsString,
  IsUUID,
  Min,
  ValidateNested,
} from 'class-validator';

export class WhatsAppGuestCartItemDto {
  @ApiPropertyOptional()
  @IsOptional()
  @IsUUID('all')
  productId?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsUUID('all')
  productSizeId?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsUUID('all')
  colorId?: string;

  @ApiPropertyOptional({ description: 'Prefijo de 8 chars del UUID (token NM-PDP)' })
  @IsOptional()
  @IsString()
  productIdPrefix?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  sku?: string;

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

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  productUrl?: string;

  @ApiProperty()
  @Type(() => Number)
  @IsInt()
  @Min(1)
  quantity!: number;

  @ApiPropertyOptional()
  @Type(() => Number)
  @IsOptional()
  @IsNumber()
  @Min(0)
  unitPrice?: number;
}

export class UpsertWhatsAppGuestCartDto {
  @ApiProperty({ description: 'Teléfono del cliente en E.164 o dígitos (no se persiste)' })
  @IsString()
  @IsNotEmpty()
  customerPhone!: string;

  @ApiPropertyOptional({ description: 'UUID del almacén del storefront' })
  @IsOptional()
  @IsUUID('all')
  warehouseId?: string;

  @ApiProperty({ type: [WhatsAppGuestCartItemDto] })
  @IsArray()
  @ArrayMinSize(1)
  @ValidateNested({ each: true })
  @Type(() => WhatsAppGuestCartItemDto)
  items!: WhatsAppGuestCartItemDto[];

  @ApiPropertyOptional({ description: 'Si true, reemplaza el carrito en lugar de fusionar líneas' })
  @IsOptional()
  @IsBoolean()
  replace?: boolean;

  @ApiPropertyOptional({ enum: ['pdp', 'bot', 'handoff'] })
  @IsOptional()
  @IsIn(['pdp', 'bot', 'handoff'])
  source?: 'pdp' | 'bot' | 'handoff';
}
