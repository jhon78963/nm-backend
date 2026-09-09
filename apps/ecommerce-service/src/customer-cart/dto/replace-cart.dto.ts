import { ApiProperty, ApiPropertyOptional } from '@nestjs/swagger';
import { Type } from 'class-transformer';
import {
  ArrayMinSize,
  IsArray,
  IsInt,
  IsNotEmpty,
  IsNumber,
  IsOptional,
  IsString,
  IsUUID,
  Min,
  ValidateNested,
} from 'class-validator';

export class UpsertCartItemDto {
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

export class ReplaceCartDto {
  @ApiProperty({ description: 'UUID del almacén del storefront' })
  @IsUUID('all')
  warehouseId!: string;

  @ApiProperty({ type: [UpsertCartItemDto] })
  @IsArray()
  @ValidateNested({ each: true })
  @Type(() => UpsertCartItemDto)
  items!: UpsertCartItemDto[];
}
