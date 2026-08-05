import type { AvatarProps } from '@nuxt/ui'

export type UserStatus = 'subscribed' | 'unsubscribed' | 'bounced'
export type SaleStatus = 'paid' | 'failed' | 'refunded'

export interface User {
  id: number
  name: string
  email: string
  avatar?: AvatarProps
  status: UserStatus
  location: string
}

export interface Mail {
  id: number
  unread?: boolean
  from: User
  subject: string
  body: string
  date: string
}

export interface Member {
  name: string
  username: string
  role: 'member' | 'owner'
  avatar: AvatarProps
}

export interface Stat {
  title: string
  icon: string
  value: number | string
  variation: number
  formatter?: (value: number) => string
}

export interface Sale {
  id: string
  date: string
  status: SaleStatus
  email: string
  amount: number
}

export interface Notification {
  id: number
  unread?: boolean
  sender: User
  body: string
  date: string
}

export type Period = 'daily' | 'weekly' | 'monthly'

export interface Range {
  start: Date
  end: Date
}

export interface Cliente {
  id: string
  tipoDocumento: string
  numeroDocumento: string
  razonSocial: string
  direccion: string
  telefono: string
  email: string
  createdAt: string
  updatedAt: string
}

export type FacturaEstado = 'emitida' | 'pagada' | 'anulada'

export interface Producto {
  id: string
  codigo: string
  nombre: string
  descripcion: string | null
  precioUnitario: number
  stock: number
  categoriaId: string
  createdAt: string
  updatedAt: string
}

export interface DetalleFactura {
  productoId: string
  cantidad: number
  precioUnitario: number
  descuento: number
  subtotal: number
}

export interface Factura {
  id: string
  numeroFactura: string
  serie: string
  clienteId: string
  usuarioId: string
  fechaEmision: string
  fechaVencimiento: string | null
  subtotal: number
  igv: number
  descuento: number
  total: number
  estado: FacturaEstado
  observaciones: string | null
  detalles?: DetalleFactura[]
  createdAt: string
  updatedAt: string
}
