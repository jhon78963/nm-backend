import { Injectable, InternalServerErrorException, Logger } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import FormData from 'form-data';
import type { StorageContext, StoredFile } from '@app/storage';

export interface UploadResult {
  success: boolean;
  files: StoredFile[];
}

/**
 * StorageClientService — cliente HTTP que llama a storage-service.
 *
 * Lo inyectan auth-service, catalog-service y finance-service.
 * Nunca habla con Firebase directamente; eso lo abstrae storage-service.
 */
@Injectable()
export class StorageClientService {
  private readonly logger = new Logger(StorageClientService.name);
  private readonly baseUrl: string;
  private readonly serviceKey: string;

  constructor(private readonly config: ConfigService) {
    this.baseUrl = config.get<string>('STORAGE_SERVICE_URL', 'http://localhost:3008');
    this.serviceKey = config.get<string>('STORAGE_SERVICE_KEY', '');
  }

  getPublicUrl(logicalPath: string): string {
    const base = this.config
      .get<string>('STORAGE_PUBLIC_BASE_URL', 'http://localhost:3000/api/v1/storage/files')
      .replace(/\/$/, '');
    const path = logicalPath.replace(/^\/+/, '');
    return `${base}/${path}`;
  }

  async upload(
    buffer: Buffer,
    mimeType: string,
    originalName: string,
    context: StorageContext,
  ): Promise<StoredFile> {
    const form = new FormData();
    form.append('context', context);
    form.append('files', buffer, { filename: originalName, contentType: mimeType });

    const url = `${this.baseUrl}/v1/storage/upload`;

    try {
      const formBuffer = form.getBuffer();
      const response = await fetch(url, {
        method: 'POST',
        headers: {
          ...form.getHeaders(),
          'x-service-key': this.serviceKey,
          'content-length': String(formBuffer.length),
        },
        body: formBuffer as unknown as BodyInit,
        signal: AbortSignal.timeout(30_000),
      });

      if (!response.ok) {
        const text = await response.text();
        throw new InternalServerErrorException(
          `storage-service → ${response.status}: ${text}`,
        );
      }

      const data = (await response.json()) as UploadResult;
      const file = data.files?.[0];
      if (!file) {
        throw new InternalServerErrorException('storage-service no devolvió archivos.');
      }

      return file;
    } catch (err) {
      this.logger.error(`Upload failed: ${(err as Error).message}`);
      throw err instanceof InternalServerErrorException
        ? err
        : new InternalServerErrorException('Error al subir archivo al storage.');
    }
  }

  async delete(path: string): Promise<void> {
    const url = `${this.baseUrl}/v1/storage/files`;

    try {
      const response = await fetch(url, {
        method: 'DELETE',
        headers: {
          'content-type': 'application/json',
          'x-service-key': this.serviceKey,
        },
        body: JSON.stringify({ path }),
        signal: AbortSignal.timeout(15_000),
      });

      if (!response.ok && response.status !== 404) {
        const text = await response.text();
        this.logger.warn(`Delete storage file failed: ${response.status} ${text}`);
      }
    } catch (err) {
      this.logger.warn(`Delete storage file error: ${(err as Error).message}`);
    }
  }
}
