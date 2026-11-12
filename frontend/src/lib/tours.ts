import type { ManufacturerAccess } from './api'

/*
 * The guided tour: a few short steps, each pointing at a part of the app
 * (an element with a matching data-tour attribute). Steps whose target isn't
 * on screen - a nav item the person can't use - are left out, so each person
 * only hears about what they have.
 *
 * Keys are versioned and must match App\Support\Tours::KNOWN.
 */

export interface TourStep {
  /** data-tour value of the element to point at; none for a centred message. */
  target?: string
  title: string
  body: string
}

export function tourFor(current: ManufacturerAccess): { key: string; steps: TourStep[] } {
  if (current.acting_as === 'dealer') {
    return {
      key: 'dealer-v1',
      steps: [
        { title: `Welcome to ${current.name} on OEM Dealer Hub`, body: `This is where ${current.dealer?.name ?? 'your dealership'} works with ${current.name}: your machines, registrations and their manuals. It's free for dealers.` },
        { target: 'switcher', title: 'One login, every brand', body: 'You carry more than one brand on OEM Dealer Hub. Switch between them here. Each brand only ever sees its own business with you.' },
        { target: 'nav-machines', title: 'Your machines', body: `Every ${current.name} machine you have. Keep each one's status current as it goes out on demo, rental or gets sold.` },
        { target: 'nav-registrations', title: 'Register sales and rentals', body: `Open a machine and register who bought or rented it, with the bill of sale. ${current.name} reviews it and you get an email.` },
        { target: 'nav-documents', title: 'Manuals by serial number', body: 'Type the serial number from any machine\'s plate, even one you didn\'t sell, to get the manuals and bulletins that cover it.' },
      ],
    }
  }

  return {
    key: 'manufacturer-v1',
    steps: [
      { title: 'Welcome to OEM Dealer Hub', body: `A quick look around ${current.name}'s account. It takes a minute, and you can take it again any time from "Take the tour".` },
      { target: 'nav-machines', title: 'Every machine, by serial number', body: 'Where each machine is, its status, and its full history. Bring in the ones you already have from a spreadsheet.' },
      { target: 'nav-registrations', title: 'Sales and rentals, approved by you', body: 'Dealers register each sale or rental with the customer and the bill of sale. You approve it, and the customer joins your records.' },
      { target: 'nav-fleet', title: 'The whole fleet at a glance', body: 'Machines by status, dealer and model, with an Excel export.' },
      { target: 'nav-documents', title: 'Manuals your dealers can find', body: 'Link each manual to models and serial ranges. Dealers find the right one by serial number.' },
      { target: 'nav-dealers', title: 'Your dealer network', body: 'Invite your dealers here. They\'re always free, and a dealer who carries other brands keeps one login.' },
      { target: 'checklist', title: 'Your next steps', body: 'This list ticks itself off as you go: logo, products, machines, dealers, manuals and your team.' },
    ],
  }
}
