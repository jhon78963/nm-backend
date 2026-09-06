import { Module } from '@nestjs/common';

import { HeaderCacheService } from './header-cache.service';
import { HeaderController } from './header.controller';
import { HeaderService } from './header.service';
import { StorefrontRevalidateService } from './storefront-revalidate.service';

@Module({
  controllers: [HeaderController],
  providers: [HeaderService, HeaderCacheService, StorefrontRevalidateService],
  exports: [HeaderService],
})
export class HeaderModule {}
