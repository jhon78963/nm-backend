import { Module } from '@nestjs/common';
import { ConfigModule } from '@nestjs/config';
import { ThrottlerModule } from '@nestjs/throttler';
import { DatabaseModule } from '@app/database';
import { AuthModule } from '@app/common/auth/auth.module';
import { ProductsModule } from './products/products.module';
import { ColorsModule } from './colors/colors.module';
import { SizesModule } from './sizes/sizes.module';
import { GendersModule } from './genders/genders.module';
import { ProductHistoryModule } from './product-history/product-history.module';
import { MediaModule } from './media/media.module';
import { HealthModule } from '@app/common/health/health.module';

@Module({
  imports: [
    ConfigModule.forRoot({ isGlobal: true, envFilePath: ['.env.local', '.env'] }),
    ThrottlerModule.forRoot([{ ttl: 60_000, limit: 120 }]),
    DatabaseModule,
    HealthModule,
    AuthModule,
    ProductsModule,
    ColorsModule,
    SizesModule,
    GendersModule,
    ProductHistoryModule,
    MediaModule,
  ],
})
export class AppModule {}
