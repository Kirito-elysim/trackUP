import { useMemo, useState } from 'react';
import type { Pagination } from '../types/trackup';

// Pagination côté client pour les tableaux dont les lignes sont déjà chargées
// en mémoire (contrairement à Companies/Tuteurs, paginés par l'API). Retourne
// les lignes de la page courante + l'objet Pagination attendu par PaginationBar.
export function useClientPagination<T>(rows: T[], defaultPageSize = 20) {
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(defaultPageSize);

  const totalPages = Math.max(1, Math.ceil(rows.length / pageSize));
  // Un filtre qui réduit la liste peut laisser `page` au-delà de la dernière
  // page disponible : on borne sans setState en render.
  const currentPage = Math.min(page, totalPages);

  const pagination = useMemo<Pagination>(
    () => ({ page: currentPage, pageSize, totalRows: rows.length, totalPages }),
    [currentPage, pageSize, rows.length, totalPages],
  );

  const pageRows = useMemo(
    () => rows.slice((currentPage - 1) * pageSize, currentPage * pageSize),
    [rows, currentPage, pageSize],
  );

  const handlePageSizeChange = (nextPageSize: number) => {
    setPageSize(nextPageSize);
    setPage(1);
  };

  return { page: currentPage, pageSize, pagination, pageRows, setPage, setPageSize: handlePageSizeChange };
}
