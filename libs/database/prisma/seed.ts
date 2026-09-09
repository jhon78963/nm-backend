import { PrismaClient } from '@prisma/client';
import * as bcrypt from 'bcryptjs';

const prisma = new PrismaClient();

const DEFAULT_TENANT_ID =
  process.env.ECOMMERCE_TENANT_ID ?? 'b14b2a6d-ff01-57e4-9004-7ece99dc46d9';
const DEFAULT_WAREHOUSE_ID =
  process.env.STORE_WAREHOUSE_ID ?? '46ea2f24-30d2-59a3-8790-8670a0105280';

const DEV_ADMIN = {
  id: '00000000-0000-4000-8000-000000000001',
  username: 'admin',
  email: 'admin@novedadesmaritex.net.pe',
  name: 'Admin',
  surname: 'NM',
  password: process.env.DEV_ADMIN_PASSWORD ?? 'Admin123!',
};

const SUPER_ADMIN_ROLE = 'Super Admin';

async function ensureTenant(): Promise<void> {
  await prisma.tenant.upsert({
    where: { id: DEFAULT_TENANT_ID },
    create: {
      id: DEFAULT_TENANT_ID,
      name: 'Novedades Maritex',
      isActive: true,
    },
    update: {
      name: 'Novedades Maritex',
      isActive: true,
    },
  });
}

async function ensureWarehouse(): Promise<void> {
  await prisma.warehouse.upsert({
    where: { id: DEFAULT_WAREHOUSE_ID },
    create: {
      id: DEFAULT_WAREHOUSE_ID,
      name: 'ANTONY',
      tenantId: DEFAULT_TENANT_ID,
      isDeleted: false,
    },
    update: {
      name: 'ANTONY',
      tenantId: DEFAULT_TENANT_ID,
      isDeleted: false,
    },
  });
}

async function ensureSuperAdminRole(): Promise<string> {
  const existing = await prisma.role.findFirst({
    where: {
      name: SUPER_ADMIN_ROLE,
      tenantId: DEFAULT_TENANT_ID,
    },
    select: { id: true },
  });

  if (existing) {
    return existing.id;
  }

  const created = await prisma.role.create({
    data: {
      name: SUPER_ADMIN_ROLE,
      guardName: 'api',
      tenantId: DEFAULT_TENANT_ID,
      isSystem: true,
    },
    select: { id: true },
  });

  return created.id;
}

async function ensureDevAdmin(roleId: string): Promise<void> {
  const passwordHash = await bcrypt.hash(DEV_ADMIN.password, 12);

  await prisma.user.upsert({
    where: { username: DEV_ADMIN.username },
    create: {
      id: DEV_ADMIN.id,
      username: DEV_ADMIN.username,
      email: DEV_ADMIN.email,
      passwordHash,
      name: DEV_ADMIN.name,
      surname: DEV_ADMIN.surname,
      tenantId: DEFAULT_TENANT_ID,
      warehouseId: DEFAULT_WAREHOUSE_ID,
      isEnabled: true,
      isDeleted: false,
      mustChangePassword: false,
    },
    update: {
      email: DEV_ADMIN.email,
      passwordHash,
      name: DEV_ADMIN.name,
      surname: DEV_ADMIN.surname,
      tenantId: DEFAULT_TENANT_ID,
      warehouseId: DEFAULT_WAREHOUSE_ID,
      isEnabled: true,
      isDeleted: false,
      mustChangePassword: false,
    },
  });

  const user = await prisma.user.findUnique({
    where: { username: DEV_ADMIN.username },
    select: { id: true },
  });

  if (!user) {
    throw new Error('No se pudo crear el usuario admin de desarrollo.');
  }

  await prisma.userRole.upsert({
    where: {
      userId_roleId: {
        userId: user.id,
        roleId,
      },
    },
    create: {
      userId: user.id,
      roleId,
    },
    update: {},
  });
}

async function main(): Promise<void> {
  await ensureTenant();
  await ensureWarehouse();
  const roleId = await ensureSuperAdminRole();
  await ensureDevAdmin(roleId);

  console.log('Dev admin seed OK');
  console.log(`  usuario:  ${DEV_ADMIN.username}`);
  console.log(`  email:    ${DEV_ADMIN.email}`);
  console.log(`  password: ${DEV_ADMIN.password}`);
}

main()
  .catch((error: unknown) => {
    console.error(error);
    process.exit(1);
  })
  .finally(async () => {
    await prisma.$disconnect();
  });
