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

const MAX_FILES_PER_PRODUCT = 5;

@Injectable()
export class MediaService {
  constructor(
    private readonly db: DatabaseService,
    private readonly storageClient: StorageClientService,
  ) {}

  async findAll(productId: string) {
    const product = await this.db.product.findFirst({
      where: { id: productId, isDeleted: false },
    });
    if (!product) throw new NotFoundException('Producto no encontrado.');

    const media = await this.db.productMedia.findMany({
      where: { productId },
      orderBy: [{ isCover: 'desc' }, { sortOrder: 'asc' }, { createdAt: 'asc' }],
    });
    return {
      data: media.map((item) => ({
        ...item,
        url: this.storageClient.getPublicUrl(item.path),
      })),
    };
  }

  async upload(req: FastifyRequest, productId: string, userId: string) {
    const product = await this.db.product.findFirst({
      where: { id: productId, isDeleted: false },
    });
    if (!product) throw new NotFoundException('Producto no encontrado.');

    const current = await this.db.productMedia.count({ where: { productId } });
    if (current >= MAX_FILES_PER_PRODUCT) {
      throw new BadRequestException(
        `El producto ya tiene el máximo de ${MAX_FILES_PER_PRODUCT} imágenes.`,
      );
    }

    const parts = req.parts();
    const uploaded: object[] = [];
    let fileCount = 0;

    for await (const part of parts) {
      if (part.type !== 'file') continue;

      fileCount++;
      if (current + fileCount > MAX_FILES_PER_PRODUCT) {
        // Consume el resto sin procesar para no dejar stream colgado
        for await (const _ of part.file) { /* drain */ }
        continue;
      }

      const mimeType = part.mimetype;
      if (!ALLOWED_MIME_TYPES.has(mimeType) || mimeType === 'application/pdf') {
        throw new BadRequestException(`Tipo de archivo no permitido: ${mimeType}`);
      }

      const chunks: Buffer[] = [];
      let size = 0;
      for await (const chunk of part.file) {
        size += chunk.length;
        if (size > MAX_FILE_SIZE_BYTES) {
          throw new PayloadTooLargeException('Una imagen excede el límite de 5 MB.');
        }
        chunks.push(chunk as Buffer);
      }

      const buffer = Buffer.concat(chunks);
      const stored = await this.storageClient.upload(
        buffer,
        mimeType,
        part.filename,
        'products',
      );

      const isCover = current === 0 && fileCount === 1;
      const media = await this.db.productMedia.create({
        data: {
          productId,
          url: this.storageClient.getPublicUrl(stored.path),
          path: stored.path,
          mimeType: stored.mimeType,
          size: stored.size,
          name: stored.name,
          sortOrder: current + fileCount - 1,
          isCover,
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

  async remove(productId: string, mediaId: string) {
    const media = await this.db.productMedia.findFirst({
      where: { id: mediaId, productId },
    });
    if (!media) throw new NotFoundException('Imagen no encontrada.');

    await this.storageClient.delete(media.path).catch(() => undefined);
    await this.db.productMedia.delete({ where: { id: mediaId } });

    // Si era la portada, asignar la siguiente
    if (media.isCover) {
      const next = await this.db.productMedia.findFirst({
        where: { productId },
        orderBy: { sortOrder: 'asc' },
      });
      if (next) {
        await this.db.productMedia.update({
          where: { id: next.id },
          data: { isCover: true },
        });
      }
    }

    return { message: 'Imagen eliminada.' };
  }

  async setCover(productId: string, mediaId: string) {
    const media = await this.db.productMedia.findFirst({
      where: { id: mediaId, productId },
    });
    if (!media) throw new NotFoundException('Imagen no encontrada.');

    await this.db.productMedia.updateMany({
      where: { productId },
      data: { isCover: false },
    });
    await this.db.productMedia.update({
      where: { id: mediaId },
      data: { isCover: true },
    });

    return { message: 'Portada actualizada.' };
  }
}
