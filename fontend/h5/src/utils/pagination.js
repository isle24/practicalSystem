export function emptyPagedList(pageSize = 10) {
  return {
    items: [],
    pagination: {
      page: 1,
      page_size: pageSize,
      total: 0,
    },
  };
}
