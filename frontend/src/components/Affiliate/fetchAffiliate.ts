import { api } from '../../api/client'

/** GET /api/affiliates(#47)で、ノード(と銘柄)の商品の欄の 1 件を取る。銘柄が無ければ brand_id を送らない */
export function fetchAffiliate(categoryId: number, brandId: number | null) {
  return api.GET('/affiliates', { params: { query: { category_id: categoryId, ...(brandId === null ? {} : { brand_id: brandId }) } } })
}
