"use client"

import { useCallback, useEffect, useRef, useState } from "react"
import { AlertTriangle, FileText, RefreshCw, Trash2, Upload } from "lucide-react"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import {
  deleteLaravelCharacterizationDocument,
  listLaravelCharacterizationDocuments,
  uploadLaravelCharacterizationDocument,
  type LaravelCharacterizationDocument,
  type LaravelCharacterizationDocumentStatus,
  type LaravelDocumentEvidence,
} from "@/lib/laravel-api"
import { P6_DOCUMENT_CONSENT_COPY } from "@/lib/p6-document-consent-copy"
import {
  deletedDocuments,
  staleDocuments,
  standardLevelDocumentEvidenceTopics,
} from "@/lib/p6-document-evidence.mjs"

const MAX_DOCUMENT_SIZE_BYTES = 50 * 1024 * 1024
const ALLOWED_DOCUMENT_EXTENSIONS = [".pdf", ".docx"]

const documentStatusLabels: Record<LaravelCharacterizationDocumentStatus, string> = {
  uploaded: "Subido",
  extracting: "Analizando",
  extracted: "Analizado",
  no_usable_evidence: "Sin datos aprovechables",
  failed: "Error",
}

function documentStatusLabel(status: LaravelCharacterizationDocumentStatus): string {
  return documentStatusLabels[status] ?? status
}

function documentStatusVariant(
  status: LaravelCharacterizationDocumentStatus,
): "default" | "secondary" | "outline" | "destructive" {
  if (status === "extracted") {
    return "default"
  }

  if (status === "failed") {
    return "destructive"
  }

  if (status === "no_usable_evidence") {
    return "outline"
  }

  return "secondary"
}

function formatSize(sizeBytes: number): string {
  if (sizeBytes >= 1024 * 1024) {
    return `${(sizeBytes / (1024 * 1024)).toFixed(1)} MB`
  }

  return `${Math.max(1, Math.round(sizeBytes / 1024))} KB`
}

function hasAllowedExtension(fileName: string): boolean {
  const lowered = fileName.toLowerCase()

  return ALLOWED_DOCUMENT_EXTENSIONS.some((extension) => lowered.endsWith(extension))
}

export function DocumentEvidencePanel({
  documentEvidence,
  csrfToken,
  onDocumentsChanged,
}: {
  documentEvidence: LaravelDocumentEvidence
  csrfToken?: string
  onDocumentsChanged: () => void
}) {
  const fileInputRef = useRef<HTMLInputElement>(null)
  const [documentsReloadCounter, setDocumentsReloadCounter] = useState(0)
  const [documents, setDocuments] = useState<LaravelCharacterizationDocument[]>([])
  const [loadingDocuments, setLoadingDocuments] = useState(true)
  const [uploading, setUploading] = useState(false)
  const [deletingId, setDeletingId] = useState<number | null>(null)
  const [pendingDeletion, setPendingDeletion] = useState<LaravelCharacterizationDocument | null>(null)
  const [panelError, setPanelError] = useState<string | null>(null)

  useEffect(() => {
    let mounted = true

    async function loadDocuments() {
      try {
        const response = await listLaravelCharacterizationDocuments()

        if (mounted) {
          setDocuments(response.data?.documents ?? [])
        }
      } catch {
        if (mounted) {
          setPanelError("No se ha podido cargar la lista de documentos.")
        }
      } finally {
        if (mounted) {
          setLoadingDocuments(false)
        }
      }
    }

    loadDocuments()

    return () => {
      mounted = false
    }
  }, [documentsReloadCounter])

  const refreshDocuments = useCallback(() => {
    setLoadingDocuments(true)
    setDocumentsReloadCounter((current) => current + 1)
  }, [])

  const handleFileSelected = async (fileList: FileList | null) => {
    const file = fileList?.[0]

    if (!file) {
      return
    }

    if (!hasAllowedExtension(file.name)) {
      setPanelError("Solo se admiten archivos PDF o DOCX.")

      return
    }

    if (file.size > MAX_DOCUMENT_SIZE_BYTES) {
      setPanelError("El archivo supera el límite de 50 MB.")

      return
    }

    setUploading(true)
    setPanelError(null)

    try {
      await uploadLaravelCharacterizationDocument(file, { csrfToken })
      refreshDocuments()
      onDocumentsChanged()
    } catch {
      setPanelError("No se ha podido subir el documento. Inténtalo de nuevo.")
    } finally {
      setUploading(false)

      if (fileInputRef.current) {
        fileInputRef.current.value = ""
      }
    }
  }

  const handleConfirmDeletion = async () => {
    if (!pendingDeletion) {
      return
    }

    setDeletingId(pendingDeletion.id)
    setPanelError(null)

    try {
      await deleteLaravelCharacterizationDocument(pendingDeletion.id, { csrfToken })
      setPendingDeletion(null)
      refreshDocuments()
      onDocumentsChanged()
    } catch {
      setPanelError("No se ha podido eliminar el documento. Inténtalo de nuevo.")
    } finally {
      setDeletingId(null)
    }
  }

  const staleDocumentList = staleDocuments(documentEvidence)
  const deletedDocumentList = deletedDocuments(documentEvidence)
  const standardLevelTopics = standardLevelDocumentEvidenceTopics(documentEvidence)

  return (
    <section className="mb-5 rounded-lg border border-border p-4">
      <div className="mb-1 flex items-center gap-2">
        <FileText className="h-4 w-4 text-muted-foreground" aria-hidden="true" />
        <h2 className="text-base font-semibold text-foreground">Documentos de tu empresa (opcional)</h2>
      </div>

      <p className="mb-3 text-sm text-muted-foreground">
        Si tienes un informe de sostenibilidad anterior u otro documento propio, súbelo y la plataforma buscará
        evidencias que apoyen tu revisión de temas.
      </p>

      <p className="mb-4 rounded-md bg-muted/50 p-3 text-sm text-muted-foreground">{P6_DOCUMENT_CONSENT_COPY}</p>

      {staleDocumentList.length > 0 ? (
        <div className="mb-4 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
          <div className="flex items-center gap-2">
            <AlertTriangle className="h-4 w-4" aria-hidden="true" />
            Los datos de la empresa cambiaron después de analizar el documento. Vuelve a analizar si quieres
            actualizar la evidencia.
          </div>
        </div>
      ) : null}

      {panelError ? (
        <div className="mb-4 rounded-md border border-destructive/40 bg-destructive/10 px-4 py-3 text-sm text-destructive">
          {panelError}
        </div>
      ) : null}

      <div className="mb-4 flex flex-wrap items-center gap-3">
        <input
          ref={fileInputRef}
          type="file"
          accept=".pdf,.docx"
          className="sr-only"
          aria-label="Seleccionar documento para subir"
          onChange={(event) => handleFileSelected(event.target.files)}
        />
        <Button type="button" disabled={uploading} onClick={() => fileInputRef.current?.click()}>
          <Upload className="h-4 w-4" aria-hidden="true" />
          {uploading ? "Subiendo..." : "Subir documento"}
        </Button>
        <span className="text-xs text-muted-foreground">PDF o DOCX, máximo 50 MB.</span>
        <Button type="button" size="sm" variant="ghost" onClick={refreshDocuments} disabled={loadingDocuments}>
          <RefreshCw className="h-4 w-4" aria-hidden="true" />
          Actualizar
        </Button>
      </div>

      {loadingDocuments ? (
        <p className="text-sm text-muted-foreground">Cargando documentos...</p>
      ) : documents.length === 0 ? (
        <p className="text-sm text-muted-foreground">Todavía no has subido ningún documento.</p>
      ) : (
        <ul className="space-y-2">
          {documents.map((document) => (
            <li
              key={document.id}
              className="flex flex-wrap items-center justify-between gap-2 rounded-md border border-border px-3 py-2"
            >
              <div className="flex min-w-0 flex-wrap items-center gap-2">
                <span className="truncate text-sm font-medium text-foreground">{document.original_filename}</span>
                <Badge variant={documentStatusVariant(document.status)}>{documentStatusLabel(document.status)}</Badge>
                <span className="text-xs text-muted-foreground">{formatSize(document.size_bytes)}</span>
              </div>
              <Button
                type="button"
                size="sm"
                variant="ghost"
                disabled={deletingId === document.id}
                onClick={() => setPendingDeletion(document)}
                aria-label={`Eliminar el documento ${document.original_filename}`}
              >
                <Trash2 className="h-4 w-4" aria-hidden="true" />
                Eliminar
              </Button>
            </li>
          ))}
        </ul>
      )}

      {deletedDocumentList.length > 0 ? (
        <div className="mt-4 space-y-1">
          {deletedDocumentList.map((document: { id: number; original_filename: string }) => (
            <p key={document.id} className="text-sm text-amber-700">
              Documento eliminado — revisar: las evidencias de &laquo;{document.original_filename}&raquo; ya no están
              disponibles.
            </p>
          ))}
        </div>
      ) : null}

      {standardLevelTopics.length > 0 ? (
        <details className="mt-4 rounded-md border border-border px-3 py-2">
          <summary className="cursor-pointer text-sm font-medium text-foreground">
            Contexto adicional encontrado en tus documentos ({standardLevelTopics.length})
          </summary>
          <ul className="mt-2 space-y-2">
            {standardLevelTopics.map(
              (
                topic: {
                  standard: string
                  kind: string
                  evidence: Array<{ document_id: number; page: number | null; snippet: string }>
                },
                index: number,
              ) => (
                <li key={`${topic.standard}-${index}`} className="text-sm text-muted-foreground">
                  <Badge variant="outline" className="mr-2">
                    {topic.standard}
                  </Badge>
                  {topic.kind === "negative" ? "El documento indica que podría no ser material. " : null}
                  {topic.evidence?.map((item, itemIndex) => (
                    <span key={itemIndex} className="block pl-1">
                      &laquo;{item.snippet}&raquo;{item.page != null ? ` (página ${item.page})` : ""}
                    </span>
                  ))}
                </li>
              ),
            )}
          </ul>
        </details>
      ) : null}

      <Dialog open={pendingDeletion !== null} onOpenChange={(open) => (!open ? setPendingDeletion(null) : null)}>
        <DialogContent className="max-w-md">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              <AlertTriangle className="h-5 w-5 text-amber-500" aria-hidden="true" />
              Eliminar documento
            </DialogTitle>
            <DialogDescription>
              Se eliminará el archivo y todas las evidencias extraídas de él. Esta acción no se puede deshacer.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter className="flex-row justify-end gap-2 sm:gap-0">
            <Button variant="ghost" onClick={() => setPendingDeletion(null)}>
              Cancelar
            </Button>
            <Button variant="destructive" disabled={deletingId !== null} onClick={handleConfirmDeletion}>
              {deletingId !== null ? "Eliminando..." : "Eliminar"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </section>
  )
}
