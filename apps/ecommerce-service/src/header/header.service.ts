import { Injectable } from '@nestjs/common';
import { DatabaseService } from '@app/database';

import {
  DEFAULT_HEADER_CONFIG,
  DEFAULT_NAVIGATION_ITEMS,
  DEFAULT_STORE_HEADER_SLUG,
} from './constants/header.defaults';
import { HeaderCacheService } from './header-cache.service';
import { StorefrontRevalidateService } from './storefront-revalidate.service';
import { UpdateHeaderDto } from './dto/update-header.dto';

export interface PublicNavigationItem {
  id: string;
  label: string;
  href: string;
  order: number;
  parentId: string | null;
}

export interface PublicHeaderResponse {
  id: string | null;
  topbarMessage: string | null;
  supportPhone: string | null;
  logoText: string;
  logoUrl: string | null;
  topBarEnabled: boolean;
  stickyEnabled: boolean;
  navigationItems: PublicNavigationItem[];
}

@Injectable()
export class HeaderService {
  constructor(
    private readonly db: DatabaseService,
    private readonly cache: HeaderCacheService,
    private readonly storefrontRevalidate: StorefrontRevalidateService,
  ) {}

  async getPublicHeader(): Promise<PublicHeaderResponse> {
    const cached = await this.cache.get<PublicHeaderResponse>();
    if (cached) {
      return cached;
    }

    const response = await this.buildPublicHeaderFromDatabase();
    await this.cache.set(response);
    return response;
  }

  async upsertHeader(dto: UpdateHeaderDto): Promise<PublicHeaderResponse> {
    const config = await this.db.$transaction(async (tx) => {
      const existing = await tx.storeHeaderConfig.findUnique({
        where: { slug: DEFAULT_STORE_HEADER_SLUG },
        include: { navigationItems: true },
      });

      const headerConfig = existing
        ? await tx.storeHeaderConfig.update({
            where: { id: existing.id },
            data: {
              topbarMessage: dto.topbarMessage ?? null,
              supportPhone: dto.supportPhone ?? null,
              logoText: dto.logoText,
              logoUrl: dto.logoUrl ?? null,
              topBarEnabled: dto.topBarEnabled ?? true,
              stickyEnabled: dto.stickyEnabled ?? true,
            },
          })
        : await tx.storeHeaderConfig.create({
            data: {
              slug: DEFAULT_STORE_HEADER_SLUG,
              topbarMessage: dto.topbarMessage ?? DEFAULT_HEADER_CONFIG.topbarMessage,
              supportPhone: dto.supportPhone ?? null,
              logoText: dto.logoText,
              logoUrl: dto.logoUrl ?? DEFAULT_HEADER_CONFIG.logoUrl,
              topBarEnabled: dto.topBarEnabled ?? DEFAULT_HEADER_CONFIG.topBarEnabled,
              stickyEnabled: dto.stickyEnabled ?? DEFAULT_HEADER_CONFIG.stickyEnabled,
            },
          });

      if (dto.navigationItems) {
        const incomingIds = dto.navigationItems
          .map((item) => item.id)
          .filter((id): id is string => Boolean(id));

        await tx.navigationItem.deleteMany({
          where: {
            headerConfigId: headerConfig.id,
            ...(incomingIds.length > 0 ? { id: { notIn: incomingIds } } : {}),
          },
        });

        for (const item of dto.navigationItems) {
          if (item.id) {
            await tx.navigationItem.update({
              where: { id: item.id },
              data: {
                label: item.label,
                href: item.href,
                order: item.order,
                isActive: item.isActive ?? true,
                parentId: item.parentId ?? null,
              },
            });
          } else {
            await tx.navigationItem.create({
              data: {
                headerConfigId: headerConfig.id,
                label: item.label,
                href: item.href,
                order: item.order,
                isActive: item.isActive ?? true,
                parentId: item.parentId ?? null,
              },
            });
          }
        }
      }

      return tx.storeHeaderConfig.findUniqueOrThrow({
        where: { id: headerConfig.id },
        include: {
          navigationItems: {
            where: { isActive: true },
            orderBy: { order: 'asc' },
          },
        },
      });
    });

    const response = this.mapToPublicResponse(config);
    await this.cache.set(response);
    await this.storefrontRevalidate.revalidateStoreHeader();
    return response;
  }

  private async buildPublicHeaderFromDatabase(): Promise<PublicHeaderResponse> {
    const config = await this.db.storeHeaderConfig.findUnique({
      where: { slug: DEFAULT_STORE_HEADER_SLUG },
      include: {
        navigationItems: {
          where: { isActive: true },
          orderBy: { order: 'asc' },
        },
      },
    });

    if (!config) {
      return this.getDefaultPublicHeader();
    }

    return this.mapToPublicResponse(config);
  }

  private mapToPublicResponse(
    config: {
      id: string;
      topbarMessage: string | null;
      supportPhone: string | null;
      logoText: string;
      logoUrl: string | null;
      topBarEnabled: boolean;
      stickyEnabled: boolean;
      navigationItems: Array<{
        id: string;
        label: string;
        href: string;
        order: number;
        parentId: string | null;
      }>;
    },
  ): PublicHeaderResponse {
    return {
      id: config.id,
      topbarMessage: config.topbarMessage,
      supportPhone: config.supportPhone,
      logoText: config.logoText,
      logoUrl: config.logoUrl,
      topBarEnabled: config.topBarEnabled,
      stickyEnabled: config.stickyEnabled,
      navigationItems: config.navigationItems.map((item) => ({
        id: item.id,
        label: item.label,
        href: item.href,
        order: item.order,
        parentId: item.parentId,
      })),
    };
  }

  private getDefaultPublicHeader(): PublicHeaderResponse {
    return {
      id: null,
      topbarMessage: DEFAULT_HEADER_CONFIG.topbarMessage,
      supportPhone: DEFAULT_HEADER_CONFIG.supportPhone,
      logoText: DEFAULT_HEADER_CONFIG.logoText,
      logoUrl: DEFAULT_HEADER_CONFIG.logoUrl,
      topBarEnabled: DEFAULT_HEADER_CONFIG.topBarEnabled,
      stickyEnabled: DEFAULT_HEADER_CONFIG.stickyEnabled,
      navigationItems: DEFAULT_NAVIGATION_ITEMS.map((item, index) => ({
        id: `default-${index}`,
        label: item.label,
        href: item.href,
        order: item.order,
        parentId: null,
      })),
    };
  }
}
