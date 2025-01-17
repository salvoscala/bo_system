1. Create a store
2. Create a product of type Booking and a variation with price 1
3. Create a content type as "Wrapper" (Guide, Office) with a required field field_bookable_entity (reference to Bookable entity content).
4. In /admin/config/people/accounts/form-display enable field "First name" and "Last name".
5. Settings can be found here: /admin/config/bo-settings
   Full discount (for offline payments) and Discount (for "in-person" events paied online) are managed with 2 Commerce Promotions created on installation.
   There are 2 custom conditions that automatically applies promotions.



// TEST:

1. Create a user with role: Bookable.
2. Create a node of type "Bookable entity" and set as author the user created before.
3. Create a node of type "Wrapper" (it could be Guide, Office...) and relate the bookable entity created before. Relate the owner too.
4. In the Wrapper node view add the block "bo_system_booking_form". You may do that via Block layout or twig.

