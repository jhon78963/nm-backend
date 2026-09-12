import { ApiPropertyOptional } from '@nestjs/swagger';
import { IsOptional, IsString } from 'class-validator';

export class WhatsAppGuestCartHandoffDto {
  @ApiPropertyOptional({ description: 'Teléfono del cliente; se hashea, no se persiste' })
  @IsOptional()
  @IsString()
  customerPhone?: string;

  @ApiPropertyOptional({ description: 'Session ID hasheado ya conocido' })
  @IsOptional()
  @IsString()
  sessionId?: string;
}
