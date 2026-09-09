import { Module } from '@nestjs/common';
import { ConfigModule } from '@nestjs/config';
import { ThrottlerModule } from '@nestjs/throttler';

import { AuthModule } from '@app/common/auth/auth.module';
import { HealthModule } from '@app/common/health/health.module';
import { DatabaseModule } from '@app/database';

import { CouponsModule } from './coupons/coupons.module';
import { CustomerAuthModule } from './customer-auth/customer-auth.module';
import { HeaderModule } from './header/header.module';
import { BannerModule } from './banner/banner.module';
import { HeroSlideModule } from './hero-slide/hero-slide.module';
import { FooterModule } from './footer/footer.module';
import { ServicesSectionModule } from './services-section/services-section.module';
import { SocialMediaModule } from './social-media/social-media.module';
import { EcommerceProductsModule } from './ecommerce-products/ecommerce-products.module';
import { CollectionsModule } from './collections/collections.module';
import { CategoryProductsModule } from './category-products/category-products.module';
import { ShopCollectionsModule } from './shop-collections/shop-collections.module';
import { ShopProductsModule } from './shop-products/shop-products.module';
import { EcommerceMediaModule } from './media/ecommerce-media.module';
import { OrdersModule } from './orders/orders.module';
import { ProductReviewsModule } from './product-reviews/product-reviews.module';
import { CustomerAccountModule } from './customer-account/customer-account.module';
import { AdminCustomersModule } from './admin-customers/admin-customers.module';
import { StoreSearchModule } from './store-search/store-search.module';
import { NewsletterModule } from './newsletter/newsletter.module';
import { CulqiModule } from './payments/culqi/culqi.module';
import { InstitutionalModule } from './institutional/institutional.module';
import { CustomerCartModule } from './customer-cart/customer-cart.module';
import { CustomerWishlistModule } from './customer-wishlist/customer-wishlist.module';

@Module({
  imports: [
    ConfigModule.forRoot({ isGlobal: true, envFilePath: ['.env.local', '.env'] }),
    ThrottlerModule.forRoot([
      { name: 'default', ttl: 60_000, limit: 120 },
      { name: 'publicProducts', ttl: 60_000, limit: 30 },
    ]),
    DatabaseModule,
    HealthModule,
    AuthModule,
    CustomerAuthModule,
    HeaderModule,
    BannerModule,
    HeroSlideModule,
    FooterModule,
    ServicesSectionModule,
    SocialMediaModule,
    EcommerceProductsModule,
    CollectionsModule,
    CategoryProductsModule,
    ShopCollectionsModule,
    ShopProductsModule,
    EcommerceMediaModule,
    OrdersModule,
    CouponsModule,
    ProductReviewsModule,
    CustomerAccountModule,
    AdminCustomersModule,
    StoreSearchModule,
    NewsletterModule,
    CulqiModule,
    InstitutionalModule,
    CustomerCartModule,
    CustomerWishlistModule,
  ],
})
export class AppModule {}
