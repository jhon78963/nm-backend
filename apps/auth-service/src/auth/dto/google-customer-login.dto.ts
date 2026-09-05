import { ApiProperty } from '@nestjs/swagger';
import { IsNotEmpty, IsString } from 'class-validator';

export class GoogleCustomerLoginDto {
  @ApiProperty({ description: 'Google ID token obtenido tras OAuth' })
  @IsString()
  @IsNotEmpty()
  id_token!: string;
}
