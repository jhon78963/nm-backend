import { ApiProperty, ApiPropertyOptional } from '@nestjs/swagger';
import { Type } from 'class-transformer';
import {
  IsArray,
  IsDateString,
  IsNotEmpty,
  IsNumber,
  IsOptional,
  IsString,
  IsUUID,
  Min,
  ValidateNested,
} from 'class-validator';

export class UpsertWishlistItemDto {
  @ApiProperty()
  @IsUUID('all')
  productId!: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsUUID('all')
  productSizeId?: string;

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
  @IsNumber()
  @Min(0)
  unitPrice!: number;

  @ApiPropertyOptional()
  @IsOptional()
  @IsDateString()
  addedAt?: string;
}

export class ReplaceWishlistDto {
  @ApiProperty({ description: 'UUID del almacén del storefront' })
  @IsUUID('all')
  warehouseId!: string;

  @ApiProperty({ type: [UpsertWishlistItemDto] })
  @IsArray()
  @ValidateNested({ each: true })
  @Type(() => UpsertWishlistItemDto)
  items!: UpsertWishlistItemDto[];
}
