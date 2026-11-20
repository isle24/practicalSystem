import { reactive } from 'vue';

export function useSocialPracticeStudentOptions(fetchOptions, selectedIds, onError) {
  const state = reactive({ items: [], loading: false, hasMore: false, keyword: '' });
  let target = null;
  let page = 0;
  let revision = 0;

  function reset(nextTarget = null, selected = []) {
    revision++;
    target = nextTarget;
    page = 0;
    Object.assign(state, { items: selected, loading: false, hasMore: false, keyword: '' });
    return target ? search('') : Promise.resolve();
  }

  async function search(keyword, append = false) {
    if (!target || (append && (state.loading || !state.hasMore))) return;
    const current = ++revision;
    const ids = selectedIds().filter(Boolean).map(Number);
    const nextPage = append ? page + 1 : 1;
    state.keyword = keyword;
    state.loading = true;
    try {
      const data = await fetchOptions({ ...target, keyword, page: nextPage, selected_ids: ids.join(',') });
      if (revision !== current) return;
      const selected = new Set(selectedIds().filter(Boolean).map(Number));
      const previous = append ? state.items : state.items.filter(row => selected.has(Number(row.student_id)));
      state.items = [...new Map([...previous, ...(data.selected || []), ...(data.items || [])]
        .map(row => [Number(row.student_id), row])).values()];
      state.hasMore = Boolean(data.has_more);
      page = nextPage;
    } catch (error) {
      if (revision === current) onError(error.message);
    } finally {
      if (revision === current) state.loading = false;
    }
  }

  return { state, reset, search, more: () => search(state.keyword, true) };
}
