export function format(value: number | undefined, decimalPoint = 2) {
  if (value == null) {
    return ""
  }

  if (isNaN(value)) {
    console.error("The given value is not a number!")
    return ""
  }

  return value.toLocaleString(navigator.language, { minimumFractionDigits: decimalPoint })
}
