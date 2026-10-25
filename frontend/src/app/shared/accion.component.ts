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
 * Botón de acción con icono y nombre. En tamaño normal (acciones de la página) se ven los dos; los chicos
 * (.btn-sm, en tablas) solo el icono, con el nombre como tooltip, salvo con [conNombre]="true".
 * Uso: <button type="button" class="btn btn-outline-primary" appAccion="Editar" icono="edit" (click)="…"></button>
 */
@Component({
  selector: 'button[appAccion], a[appAccion]',
  imports: [IconDirective],
  template: `<i antIcon [type]="icono()" theme="outline"></i><span class="btn-accion-nombre">{{ appAccion() }}</span>`,
  host: {
    class: 'btn-accion',
    '[class.con-nombre]': 'conNombre()',
    '[attr.title]': 'ayuda() || appAccion()',
    '[attr.aria-label]': 'appAccion()'
  }
})
export class AccionComponent {
  /** Nombre de la acción. */
  appAccion = input.required<string>();
  /** Icono de ant-design (outline), p. ej. "edit", "delete", "eye". */
  icono = input.required<string>();
  /** Tooltip en lugar del nombre (p. ej. por qué está deshabilitado). */
  ayuda = input<string>('');
  /** Muestra el nombre también en un botón chico (.btn-sm). */
  conNombre = input(false);

  constructor() {
    inject(IconService).addIcon(...ICONOS_ACCION);
  }
}
