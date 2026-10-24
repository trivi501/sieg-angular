import { Component, inject, input } from '@angular/core';
import { IconDirective, IconService } from '@ant-design/icons-angular';
import {
  ArrowRightOutline,
  CalendarOutline,
  CarOutline,
  CheckOutline,
  CloseCircleOutline,
  CloseOutline,
  DeleteOutline,
  DownloadOutline,
  EditOutline,
  EyeOutline,
  FileAddOutline,
  FileDoneOutline,
  FilePdfOutline,
  FileTextOutline,
  HistoryOutline,
  ImportOutline,
  ExportOutline,
  InboxOutline,
  PrinterOutline,
  SafetyCertificateOutline,
  ShopOutline,
  StopOutline,
  SyncOutline,
  UndoOutline,
  UserSwitchOutline,
  PlusOutline,
  SearchOutline,
  ClearOutline,
  UploadOutline,
  FileExcelOutline,
  CalculatorOutline,
  DatabaseOutline,
  ArrowLeftOutline,
  HomeOutline,
  DollarOutline,
  SwapOutline,
  RollbackOutline,
  SendOutline,
  CheckCircleOutline,
  ToolOutline
} from '@ant-design/icons-angular/icons';

/** Iconos de las acciones (botones y menú contextual); se registran una vez. */
export const ICONOS_ACCION = [
  ArrowRightOutline,
  CalendarOutline,
  CarOutline,
  CheckOutline,
  CloseCircleOutline,
  CloseOutline,
  DeleteOutline,
  DownloadOutline,
  EditOutline,
  EyeOutline,
  FileAddOutline,
  FileDoneOutline,
  FilePdfOutline,
  FileTextOutline,
  HistoryOutline,
  ImportOutline,
  ExportOutline,
  InboxOutline,
  PrinterOutline,
  SafetyCertificateOutline,
  ShopOutline,
  StopOutline,
  SyncOutline,
  UndoOutline,
  UserSwitchOutline,
  PlusOutline,
  SearchOutline,
  ClearOutline,
  UploadOutline,
  FileExcelOutline,
  CalculatorOutline,
  DatabaseOutline,
  ArrowLeftOutline,
  HomeOutline,
  DollarOutline,
  SwapOutline,
  RollbackOutline,
  SendOutline,
  CheckCircleOutline,
  ToolOutline
];

/**
 * Botón de acción solo con icono: el nombre de la acción queda como tooltip y para lectores de pantalla.
 * Uso: <button type="button" class="btn btn-sm btn-outline-primary" appAccion="Editar" icono="edit" (click)="…"></button>
 */
@Component({
  selector: 'button[appAccion], a[appAccion]',
  imports: [IconDirective],
  template: `<i antIcon [type]="icono()" theme="outline"></i>`,
  host: {
    class: 'btn-accion',
    '[attr.title]': 'appAccion()',
    '[attr.aria-label]': 'appAccion()'
  }
})
export class AccionComponent {
  /** Nombre de la acción (tooltip). */
  appAccion = input.required<string>();
  /** Icono de ant-design (outline), p. ej. "edit", "delete", "eye". */
  icono = input.required<string>();

  constructor() {
    inject(IconService).addIcon(...ICONOS_ACCION);
  }
}
