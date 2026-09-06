import { Injectable, Logger } from '@nestjs/common';

export const STORE_HEADER_CACHE_TAG = 'store-header';

@Injectable()
export class StorefrontRevalidateService {
  private readonly logger = new Logger(StorefrontRevalidateService.name);

  async revalidateStoreHeader(): Promise<void> {
    const url = process.env.STOREFRONT_REVALIDATE_URL?.trim();
    const secret = process.env.REVALIDATE_SECRET?.trim();

    if (!url || !secret) {
      this.logger.debug('Storefront revalidation skipped (not configured)');
      return;
    }

    try {
      const response = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ secret, tag: STORE_HEADER_CACHE_TAG }),
        signal: AbortSignal.timeout(10_000),
      });

      if (!response.ok) {
        const body = await response.text();
        this.logger.warn(`Storefront revalidation failed: ${response.status} ${body}`);
        return;
      }

      this.logger.log(`Storefront cache tag "${STORE_HEADER_CACHE_TAG}" revalidated`);
    } catch (error) {
      this.logger.warn(`Storefront revalidation error: ${error}`);
    }
  }
}
