import { Test, TestingModule } from '@nestjs/testing';
import { NotFoundException, ConflictException } from '@nestjs/common';
import { ProductsService } from './products.service';
import { ProductHistoryService } from '../product-history/product-history.service';
import { DatabaseService } from '@app/database';
import { faker } from '@faker-js/faker';

// ─── Factories ────────────────────────────────────────────────────────────────

function makeProduct(overrides = {}) {
  return {
    id: faker.string.uuid(),
    name: faker.commerce.productName(),
    description: faker.commerce.productDescription(),
    barcode: faker.string.numeric(13),
    genderId: faker.string.uuid(),
    vendorId: faker.string.uuid(),
    warehouseId: faker.string.uuid(),
    isFeatured: false,
    isOnSale: false,
    wooStatus: 'draft',
    isDeleted: false,
    deletionTime: null,
    createdById: faker.string.uuid(),
    productSizes: [],
    gender: { id: faker.string.uuid(), name: 'Mujer' },
    vendor: { id: faker.string.uuid(), name: 'Proveedor SA' },
    ...overrides,
  };
}

function makeProductSize(productId: string, overrides = {}) {
  return {
    id: faker.string.uuid(),
    productId,
    sizeId: faker.string.uuid(),
    barcode: faker.string.numeric(13),
    purchasePrice: 25.00,
    salePrice: 45.00,
    minSalePrice: 35.00,
    isDeleted: false,
    productSizeColors: [],
    inventoryBalances: [],
    size: { id: faker.string.uuid(), description: 'M' },
    ...overrides,
  };
}

// ─── Mocks ────────────────────────────────────────────────────────────────────

const mockDb = {
  $transaction: jest.fn(),
  $queryRaw: jest.fn().mockResolvedValue([]),
  product: {
    create: jest.fn(),
    findMany: jest.fn(),
    findFirst: jest.fn(),
    update: jest.fn(),
    count: jest.fn(),
  },
  productSize: {
    create: jest.fn(),
    findFirst: jest.fn(),
    findMany: jest.fn(),
    update: jest.fn(),
  },
  productSizeColor: {
    create: jest.fn(),
    findFirst: jest.fn(),
    delete: jest.fn(),
    createMany: jest.fn(),
  },
};

const mockHistory = { record: jest.fn().mockResolvedValue({}) };

// ═══════════════════════════════════════════════════════════════════════════════
// SUITE: ProductsService
// ═══════════════════════════════════════════════════════════════════════════════

describe('ProductsService', () => {
  let service: ProductsService;

  beforeEach(async () => {
    const module: TestingModule = await Test.createTestingModule({
      providers: [
        ProductsService,
        { provide: DatabaseService, useValue: mockDb },
        { provide: ProductHistoryService, useValue: mockHistory },
      ],
    }).compile();

    service = module.get<ProductsService>(ProductsService);
    jest.clearAllMocks();
  });

  // ── create ────────────────────────────────────────────────────────────────

  describe('create()', () => {
    it('crea producto con tallas en una transacción atómica', async () => {
      const product = makeProduct();
      const txMock = {
        product: { create: jest.fn().mockResolvedValue(product) },
        productSize: { create: jest.fn().mockResolvedValue(makeProductSize(product.id)) },
        productSizeColor: { createMany: jest.fn().mockResolvedValue({ count: 1 }) },
        productHistory: { create: jest.fn().mockResolvedValue({}) },
      };
      mockDb.$transaction.mockImplementation((fn: (tx: typeof txMock) => unknown) => fn(txMock));
      mockDb.product.findFirst.mockResolvedValue(product);

      const result = await service.create(
        {
          name: product.name,
          genderId: product.genderId,
          warehouseId: product.warehouseId,
          sizes: [
            {
              sizeId: faker.string.uuid(),
              purchasePrice: 25,
              salePrice: 45,
              colorIds: [faker.string.uuid()],
            },
          ],
        },
        product.createdById,
      );

      expect(txMock.product.create).toHaveBeenCalledTimes(1);
      expect(txMock.productSize.create).toHaveBeenCalledTimes(1);
      expect(txMock.productSizeColor.createMany).toHaveBeenCalledTimes(1);
      expect(result).toMatchObject({
        message: 'Producto creado correctamente.',
        productId: product.id,
      });
    });

    it('crea producto sin tallas si sizes es undefined', async () => {
      const product = makeProduct();
      const txMock = {
        product: { create: jest.fn().mockResolvedValue(product) },
        productSize: { create: jest.fn() },
        productSizeColor: { createMany: jest.fn() },
        productHistory: { create: jest.fn().mockResolvedValue({}) },
      };
      mockDb.$transaction.mockImplementation((fn: (tx: typeof txMock) => unknown) => fn(txMock));
      mockDb.product.findFirst.mockResolvedValue(product);

      await service.create({ name: product.name, genderId: product.genderId, warehouseId: product.warehouseId }, product.createdById);

      expect(txMock.productSize.create).not.toHaveBeenCalled();
    });
  });

  // ── findAll ───────────────────────────────────────────────────────────────

  describe('findAll()', () => {
    it('retorna paginación correcta con meta.lastPage', async () => {
      const products = [makeProduct(), makeProduct()];
      mockDb.$transaction.mockResolvedValue([products, 45]);

      const result = await service.findAll(
        { page: 2, perPage: 20 },
        faker.string.uuid(),
      );

      expect(result.meta).toMatchObject({
        total: 45,
        page: 2,
        perPage: 20,
        lastPage: 3,
      });
      expect(result.data).toHaveLength(2);
    });

    it('aplica filtro de búsqueda por nombre (case insensitive)', async () => {
      mockDb.$transaction.mockResolvedValue([[], 0]);

      const result = await service.findAll({ search: 'polo' }, faker.string.uuid());

      expect(mockDb.$queryRaw).toHaveBeenCalled();
      expect(mockDb.$transaction).toHaveBeenCalledTimes(1);
      expect(result.data).toEqual([]);
      expect(result.meta.total).toBe(0);
    });

    it('ordena por fecha de creación descendente cuando sortBy=createdAt', async () => {
      mockDb.$transaction.mockImplementation(async (queries: Promise<unknown>[]) =>
        Promise.all(queries),
      );
      mockDb.product.findMany.mockResolvedValue([]);
      mockDb.product.count.mockResolvedValue(0);

      await service.findAll({ sortBy: 'createdAt' as never }, faker.string.uuid());

      expect(mockDb.product.findMany).toHaveBeenCalledWith(
        expect.objectContaining({
          orderBy: { createdAt: 'desc' },
        }),
      );
    });

    it('incluye búsqueda por UUID en el filtro OR', async () => {
      const productId = 'b7bff81e-78d8-4fdf-ac73-a09e3db8e6b1';
      mockDb.$transaction.mockImplementation(async (queries: Promise<unknown>[]) =>
        Promise.all(queries),
      );
      mockDb.product.findMany.mockResolvedValue([]);
      mockDb.product.count.mockResolvedValue(0);

      await service.findAll({ search: productId }, faker.string.uuid());

      expect(mockDb.product.findMany).toHaveBeenCalledWith(
        expect.objectContaining({
          where: expect.objectContaining({
            OR: expect.arrayContaining([{ id: productId }]),
          }),
        }),
      );
    });

    it('aplica filtro de búsqueda por barcode de talla', async () => {
      mockDb.$transaction.mockImplementation(async (queries: Promise<unknown>[]) =>
        Promise.all(queries),
      );
      mockDb.product.findMany.mockResolvedValue([]);
      mockDb.product.count.mockResolvedValue(0);

      await service.findAll({ search: '123321' }, faker.string.uuid());

      expect(mockDb.product.findMany).toHaveBeenCalledWith(
        expect.objectContaining({
          where: expect.objectContaining({
            OR: expect.arrayContaining([
              expect.objectContaining({
                productSizes: {
                  some: {
                    isDeleted: false,
                    barcode: { contains: '123321' },
                  },
                },
              }),
            ]),
          }),
        }),
      );
    });

    it('aplica filtro de productos con imágenes', async () => {
      mockDb.$transaction.mockImplementation(async (queries: Promise<unknown>[]) =>
        Promise.all(queries),
      );
      mockDb.product.findMany.mockResolvedValue([]);
      mockDb.product.count.mockResolvedValue(0);

      await service.findAll({ hasImages: true }, faker.string.uuid());

      expect(mockDb.product.findMany).toHaveBeenCalledWith(
        expect.objectContaining({
          where: expect.objectContaining({
            media: { some: {} },
          }),
        }),
      );
    });

    it('aplica filtro de productos sin imágenes', async () => {
      mockDb.$transaction.mockImplementation(async (queries: Promise<unknown>[]) =>
        Promise.all(queries),
      );
      mockDb.product.findMany.mockResolvedValue([]);
      mockDb.product.count.mockResolvedValue(0);

      await service.findAll({ hasImages: false }, faker.string.uuid());

      expect(mockDb.product.findMany).toHaveBeenCalledWith(
        expect.objectContaining({
          where: expect.objectContaining({
            media: { none: {} },
          }),
        }),
      );
    });
  });

  describe('findById()', () => {
    it('lanza NotFoundException para producto inexistente', async () => {
      mockDb.product.findFirst.mockResolvedValue(null);

      await expect(service.findById(faker.string.uuid())).rejects.toThrow(
        NotFoundException,
      );
    });

    it('no retorna productos soft-deleted', async () => {
      mockDb.product.findFirst.mockResolvedValue(null);

      await expect(
        service.findById(faker.string.uuid()),
      ).rejects.toThrow(NotFoundException);

      expect(mockDb.product.findFirst).toHaveBeenCalledWith(
        expect.objectContaining({ where: expect.objectContaining({ isDeleted: false }) }),
      );
    });
  });

  // ── addSize ───────────────────────────────────────────────────────────────

  describe('addSize()', () => {
    it('agrega talla al producto correctamente', async () => {
      const product = makeProduct();
      const sizeId = faker.string.uuid();
      const ps = makeProductSize(product.id, { sizeId });

      mockDb.product.findFirst.mockResolvedValue(product);
      mockDb.productSize.findFirst.mockResolvedValue(null);
      const txMock = {
        productSize: { create: jest.fn().mockResolvedValue(ps) },
        productSizeColor: { count: jest.fn().mockResolvedValue(0) },
        color: { findFirst: jest.fn().mockResolvedValue(null), create: jest.fn().mockResolvedValue({ id: 'no-color-id' }) },
        inventoryBalance: { upsert: jest.fn().mockResolvedValue({}) },
      };
      mockDb.$transaction.mockImplementation((fn: (tx: typeof txMock) => unknown) => fn(txMock));

      const result = await service.addSize(
        product.id,
        { sizeId, purchasePrice: 25, salePrice: 45, stock: 10 },
        faker.string.uuid(),
      );

      expect(txMock.productSize.create).toHaveBeenCalledTimes(1);
      expect(txMock.inventoryBalance.upsert).toHaveBeenCalledTimes(1);
      expect(result).toMatchObject({ message: 'Talla agregada correctamente.' });
    });

    it('lanza ConflictException si la talla ya existe en el producto', async () => {
      const product = makeProduct();
      const ps = makeProductSize(product.id);

      mockDb.product.findFirst.mockResolvedValue(product);
      mockDb.productSize.findFirst.mockResolvedValue(ps); // Ya existe

      await expect(
        service.addSize(product.id, { sizeId: ps.sizeId, purchasePrice: 25, salePrice: 45 }, faker.string.uuid()),
      ).rejects.toThrow(ConflictException);
    });
  });

  // ── remove (soft-delete) ──────────────────────────────────────────────────

  describe('remove()', () => {
    it('hace soft-delete (isDeleted: true), no elimina físicamente', async () => {
      const product = makeProduct();
      mockDb.product.findFirst.mockResolvedValue(product);
      mockDb.product.update.mockResolvedValue({ ...product, isDeleted: true });

      await service.remove(product.id, faker.string.uuid());

      expect(mockDb.product.update).toHaveBeenCalledWith({
        where: { id: product.id },
        data: expect.objectContaining({ isDeleted: true, deletionTime: expect.any(Date) }),
      });
    });
  });

  // ── searchForPos ──────────────────────────────────────────────────────────

  describe('searchForPos()', () => {
    it('busca por barcode de talla', async () => {
      mockDb.productSize.findMany = jest.fn().mockResolvedValue([]);

      await service.searchForPos('7501234', faker.string.uuid());

      expect(mockDb.productSize.findMany).toHaveBeenCalledWith(
        expect.objectContaining({ take: 20 }),
      );
    });
  });
});
