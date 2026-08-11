/**
 * Seeds the central PostgreSQL database with a small starter taxonomy and a few
 * assets so a fresh install has something to sync down.
 *
 *   npm run db:seed
 */
import { PrismaMariaDb } from "@prisma/adapter-mariadb";

import { PrismaClient } from "../src/generated/prisma/client.ts";

const connectionString = process.env.DATABASE_URL;
if (!connectionString) {
  console.error("DATABASE_URL is not set. Copy .env.example to .env first.");
  process.exit(1);
}

const prisma = new PrismaClient({
  adapter: new PrismaMariaDb(connectionString),
});

const CATEGORIES = [
  {
    id: "0f2a1c34-1111-4a11-8a11-000000000001",
    name: "Laptops",
    description: "Portable computers issued to staff and students.",
    defaultLoanDays: 14,
    customFields: {
      fields: [
        { key: "processor", label: "Processor", type: "text", required: true },
        {
          key: "ram",
          label: "RAM",
          type: "select",
          required: true,
          options: ["8GB", "16GB", "32GB"],
        },
        { key: "storage_gb", label: "Storage (GB)", type: "number", required: false },
      ],
    },
  },
  {
    id: "0f2a1c34-1111-4a11-8a11-000000000002",
    name: "Networking",
    description: "Routers, switches, and access points.",
    defaultLoanDays: 30,
    customFields: {
      fields: [
        { key: "mac_address", label: "MAC Address", type: "text", required: true },
        { key: "ports", label: "Port count", type: "number", required: false },
        { key: "poe", label: "PoE capable", type: "boolean", required: false },
      ],
    },
  },
  {
    id: "0f2a1c34-1111-4a11-8a11-000000000003",
    name: "Peripherals",
    description: "Projectors, monitors, and accessories.",
    defaultLoanDays: 7,
    customFields: {
      fields: [
        { key: "connector", label: "Connector", type: "select", required: false, options: ["HDMI", "VGA", "USB-C"] },
      ],
    },
  },
];

const EQUIPMENT = [
  {
    id: "0f2a1c34-2222-4a22-8a22-000000000001",
    assetTag: "ICT-LT-0001",
    serialNumber: "PF3ZK9QA",
    categoryId: CATEGORIES[0].id,
    modelName: "ThinkPad T14 Gen 4",
    brand: "Lenovo",
    location: "ICT Storeroom / Shelf A",
    specifications: { processor: "Intel Core i5-1335U", ram: "16GB", storage_gb: 512 },
  },
  {
    id: "0f2a1c34-2222-4a22-8a22-000000000002",
    assetTag: "ICT-LT-0002",
    serialNumber: "PF3ZK9QB",
    categoryId: CATEGORIES[0].id,
    modelName: "Latitude 5440",
    brand: "Dell",
    location: "ICT Storeroom / Shelf A",
    specifications: { processor: "Intel Core i7-1355U", ram: "32GB", storage_gb: 1024 },
  },
  {
    id: "0f2a1c34-2222-4a22-8a22-000000000003",
    assetTag: "ICT-NW-0001",
    serialNumber: "FGL2249L0AB",
    categoryId: CATEGORIES[1].id,
    modelName: "Catalyst 2960-X",
    brand: "Cisco",
    location: "Server Room / Rack B",
    specifications: { mac_address: "00:1A:2B:3C:4D:5E", ports: 24, poe: true },
  },
  {
    id: "0f2a1c34-2222-4a22-8a22-000000000004",
    assetTag: "ICT-PR-0001",
    serialNumber: null,
    categoryId: CATEGORIES[2].id,
    modelName: "EB-X51 Projector",
    brand: "Epson",
    location: "Media Room",
    specifications: { connector: "HDMI" },
  },
];

for (const category of CATEGORIES) {
  await prisma.category.upsert({
    where: { id: category.id },
    create: category,
    update: category,
  });
}
console.log(`✓ ${CATEGORIES.length} categories`);

for (const item of EQUIPMENT) {
  await prisma.equipment.upsert({
    where: { id: item.id },
    create: item,
    update: item,
  });
}
console.log(`✓ ${EQUIPMENT.length} equipment items`);

await prisma.$disconnect();
console.log("Seed complete.");
