import { ApiProperty } from '@nestjs/swagger';
import { IsUUID } from 'class-validator';

export class PublicProductStockQueryDto {
  @ApiProperty({ description: 'UUID del almacén del tenant' })
  @IsUUID('all')
  warehouseId!: string;
}
