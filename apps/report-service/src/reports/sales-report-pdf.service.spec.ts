import { Test, TestingModule } from '@nestjs/testing';

import { DocumentClientService } from '@app/document-client';

import { ReportsService } from './reports.service';
import { SalesReportPdfService } from './sales-report-pdf.service';

const mockReportsService = {
  getDailySalesReport: jest.fn(),
  getPeriodSalesReport: jest.fn(),
  getMonthlySalesReport: jest.fn(),
};

const mockDocumentClient = {
  generatePdf: jest.fn().mockResolvedValue(Buffer.from('pdf')),
};

describe('SalesReportPdfService', () => {
  let service: SalesReportPdfService;

  beforeEach(async () => {
    jest.clearAllMocks();

    const module: TestingModule = await Test.createTestingModule({
      providers: [
        SalesReportPdfService,
        { provide: ReportsService, useValue: mockReportsService },
        { provide: DocumentClientService, useValue: mockDocumentClient },
      ],
    }).compile();

    service = module.get(SalesReportPdfService);
  });

  it('generateMonthly usa reporte mensual y plantilla sales-report', async () => {
    mockReportsService.getMonthlySalesReport.mockResolvedValue({
      monthIso: '2026-09',
      monthLabel: 'septiembre 2026',
      summary: {
        transactionCount: 10,
        itemsSold: 25,
        totalAmount: 1500,
        averageTicket: 150,
        cash: 500,
        digital: 1000,
        averageDaily: 75,
        daysWithSales: 20,
      },
      paymentBreakdown: [{ label: 'Efectivo', count: 5, amount: 500 }],
      dailyBreakdown: [
        {
          date: '01/09',
          dayOfWeek: 'Lun',
          transactions: 2,
          cash: 100,
          digital: 200,
          total: 300,
        },
      ],
    });

    const result = await service.generateMonthly(
      '2026-09',
      'a1111111-1111-4111-8111-111111111111',
    );

    expect(mockReportsService.getMonthlySalesReport).toHaveBeenCalledWith(
      '2026-09',
      'a1111111-1111-4111-8111-111111111111',
    );
    expect(mockDocumentClient.generatePdf).toHaveBeenCalledWith(
      'sales-report',
      expect.objectContaining({
        title: 'Reporte de Ventas Mensual',
        subtitle: 'septiembre 2026',
        isDaily: false,
        dailyBreakdown: expect.arrayContaining([
          expect.objectContaining({
            date: '01/09',
            total: expect.any(String),
          }),
        ]),
      }),
    );
    expect(result).toEqual(Buffer.from('pdf'));
  });
});
