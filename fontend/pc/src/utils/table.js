/** 计算列表跨页连续序号 */
export function tableSequence(index, pagination = {}, defaultPageSize = 20) {
  const page = Math.max(1, Number(pagination.page || 1));
  const pageSize = Math.max(1, Number(pagination.page_size || defaultPageSize));
  return (page - 1) * pageSize + index + 1;
}
