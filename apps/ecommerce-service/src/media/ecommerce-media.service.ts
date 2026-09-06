import {
  BadRequestException,
  Injectable,
  NotFoundException,
  PayloadTooLargeException,
} from '@nestjs/common';
import { DatabaseService } from '@app/database';
import { StorageClientService } from '@app/storage-client';
import { ALLOWED_MIME_TYPES, MAX_FILE_SIZE_BYTES } from '@app/storage';
import type { FastifyRequest } from 'fastify';
import '@fastify/multipart';

export type EcommerceMediaSort = 'newest' | 'oldest' | 'smallest' | 'largest';

export interface EcommerceMediaListQuery {
  search?: string;
  mimeType?: string;
  sort?: EcommerceMediaSort;
  page?: number;
  limit?: number;
}

@Injectable()
export class EcommerceMediaService {
  constructor(
    private readonly db: DatabaseService,
    private readonly storageClient: StorageClientService,
  ) {}

  async findAll(query: EcommerceMediaListQuery) {
    const page = Math.max(1, query.page ?? 1);
    const limit = Math.min(100, Math.max(1, query.limit ?? 50));
    const skip = (page - 1) * limit;
    const search = query.search?.trim();
    const mimeType = query.mimeType?.trim();

    const where = {
      ...(search
        ? {
            OR: [
              { name: { contains: search, mode: 'insensitive' as const } },
              { originalName: { contains: search, mode: 'insensitive' as const } },
            ],
          }
        : {}),
      ...(mimeType ? { mimeType: { contains: mimeType, mode: 'insensitive' as const } } : {}),
    };

    const orderBy = this.resolveOrderBy(query.sort);

    const [data, total] = await Promise.all([
      this.db.ecommerceMedia.findMany({
        where,
        orderBy,
        skip,
        take: limit,
      }),
      this.db.ecommerceMedia.count({ where }),
    ]);

    return {
      data: data.map((item) => ({
        ...item,
        url: this.storageClient.getPublicUrl(item.path),
      })),
      meta: {
        total,
        page,
        limit,
        totalPages: Math.max(1, Math.ceil(total / limit)),
      },
    };
  }

  async upload(req: FastifyRequest, userId: string) {
    const parts = req.parts();
    const uploaded: object[] = [];

    for await (const part of parts) {
      if (part.type !== 'file') continue;

      const mimeType = part.mimetype;
      if (!ALLOWED_MIME_TYPES.has(mimeType)) {
        throw new BadRequestException(`Tipo de archivo no permitido: ${mimeType}`);
      }

      const chunks: Buffer[] = [];
      let size = 0;
      for await (const chunk of part.file) {
        size += chunk.length;
        if (size > MAX_FILE_SIZE_BYTES) {
          throw new PayloadTooLargeException('Un archivo excede el límite de 5 MB.');
        }
        chunks.push(chunk as Buffer);
      }

      const buffer = Buffer.concat(chunks);
      const stored = await this.storageClient.upload(
        buffer,
        mimeType,
        part.filename,
        'ecommerce',
      );

      const media = await this.db.ecommerceMedia.create({
        data: {
          url: this.storageClient.getPublicUrl(stored.path),
          path: stored.path,
          mimeType: stored.mimeType,
          size: stored.size,
          name: stored.name,
          originalName: part.filename,
          uploadedById: userId,
        },
      });

      uploaded.push(media);
    }

    if (uploaded.length === 0) {
      throw new BadRequestException('No se encontraron archivos válidos para subir.');
    }

    return { uploaded };
  }

  async remove(mediaId: string) {
    const media = await this.db.ecommerceMedia.findUnique({ where: { id: mediaId } });
    if (!media) throw new NotFoundException('Archivo no encontrado.');

    await this.storageClient.delete(media.path).catch(() => undefined);
    await this.db.ecommerceMedia.delete({ where: { id: mediaId } });

    return { message: 'Archivo eliminado.' };
  }

  async removeMany(ids: string[]) {
    const uniqueIds = [...new Set(ids.filter(Boolean))];
    if (uniqueIds.length === 0) {
      throw new BadRequestException('Debes indicar al menos un archivo para eliminar.');
    }

    const mediaItems = await this.db.ecommerceMedia.findMany({
      where: { id: { in: uniqueIds } },
    });

    await Promise.all(
      mediaItems.map((item) => this.storageClient.delete(item.path).catch(() => undefined)),
    );

    await this.db.ecommerceMedia.deleteMany({
      where: { id: { in: mediaItems.map((item) => item.id) } },
    });

    return { deleted: mediaItems.length };
  }

  private resolveOrderBy(sort?: EcommerceMediaSort) {
    switch (sort) {
      case 'oldest':
        return { createdAt: 'asc' as const };
      case 'smallest':
        return { size: 'asc' as const };
      case 'largest':
        return { size: 'desc' as const };
      case 'newest':
      default:
        return { createdAt: 'desc' as const };
    }
  }
}
